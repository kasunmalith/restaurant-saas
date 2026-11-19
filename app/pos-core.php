<?php
declare(strict_types=1);

function pos_cents(string $value): int {
    if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('Enter a valid non-negative amount with up to two decimals.');
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $cents = (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
    if ($cents > 999999999999) throw new InvalidArgumentException('Amount exceeds the supported currency limit.');
    return $cents;
}
function pos_money(int $cents): string {
    if ($cents < 0 || $cents > 999999999999) throw new InvalidArgumentException('Amount exceeds the supported currency limit.');
    return intdiv($cents, 100).'.'.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}
function pos_percent_charge(int $subtotalCents, string $percent): int {
    $basisPoints = pos_cents($percent);
    if ($basisPoints > 10000) throw new InvalidArgumentException('Service charge percentage must be between 0 and 100.');
    return intdiv($subtotalCents * $basisPoints + 5000, 10000);
}
function pos_item_price(array $item, ?string $portion, PDO $db, int $restaurantId): int {
    if ((int)$item['is_active'] !== 1 || (int)$item['is_available'] !== 1) {
        throw new InvalidArgumentException($item['name'].' is unavailable.');
    }
    require_once __DIR__.'/menu-options.php';
    return pos_cents(menu_option_price($db,$restaurantId,$item,$portion));
}
function pos_invoice_totals(PDO $db, int $invoiceId, int $restaurantId): array {
    $query = $db->prepare('SELECT line_total FROM pos_invoice_items WHERE invoice_id=?');
    $query->execute([$invoiceId]);
    $subtotal = 0;
    foreach ($query as $row) $subtotal += pos_cents((string)$row['line_total']);
    $query = $db->prepare('SELECT discount,service_charge,tax,packaging_charge,delivery_charge,paid_total FROM pos_invoices WHERE id=? AND restaurant_id=? FOR UPDATE');
    $query->execute([$invoiceId, $restaurantId]);
    $invoice = $query->fetch();
    if (!$invoice) throw new RuntimeException('Bill not found.');
    $discount = pos_cents($invoice['discount']);
    if ($discount > $subtotal) throw new InvalidArgumentException('Discount cannot exceed item subtotal.');
    $total = $subtotal - $discount + pos_cents($invoice['service_charge']) + pos_cents($invoice['tax']) + pos_cents($invoice['packaging_charge']) + pos_cents($invoice['delivery_charge']);
    $paid = pos_cents($invoice['paid_total']);
    if ($paid > $total) throw new InvalidArgumentException('Bill total cannot be lower than payments already recorded.');
    $status = $paid >= $total ? 'PAID' : ($paid === 0 ? 'UNPAID' : 'PARTIALLY_PAID');
    $update = $db->prepare('UPDATE pos_invoices SET subtotal=?,total=?,payment_status=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');
    $update->execute([pos_money($subtotal), pos_money($total), $status, $invoiceId, $restaurantId]);
    return ['subtotal' => pos_money($subtotal), 'total' => pos_money($total), 'paid_total' => pos_money($paid), 'payment_status' => $status];
}
function pos_complete_paid_order(PDO $db, int $invoiceId, int $restaurantId, int $userId): ?string {
    $q=$db->prepare('SELECT i.payment_status,o.id order_id,o.status FROM pos_invoices i JOIN orders o ON o.id=i.order_id AND o.restaurant_id=i.restaurant_id WHERE i.id=? AND i.restaurant_id=? FOR UPDATE');
    $q->execute([$invoiceId,$restaurantId]);$record=$q->fetch();
    if(!$record)throw new InvalidArgumentException('Bill or order not found.');
    if($record['payment_status']!=='PAID'||in_array($record['status'],['REJECTED','CANCELLED'],true))return (string)$record['status'];
    if($record['status']==='COMPLETED')return 'COMPLETED';
    $q=$db->prepare('UPDATE orders SET status="COMPLETED",updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');
    $q->execute([(int)$record['order_id'],$restaurantId]);
    $q=$db->prepare('INSERT INTO order_status_history(restaurant_id,order_id,user_id,previous_status,new_status,created_at) VALUES(?,?,?,? ,"COMPLETED",UTC_TIMESTAMP())');
    $q->execute([$restaurantId,(int)$record['order_id'],$userId,$record['status']]);
    return 'COMPLETED';
}
function pos_send_kot(PDO $db, int $orderId, int $restaurantId, int $userId): ?int {
    $items = $db->prepare('SELECT id,item_name,portion,quantity,special_instruction FROM order_items WHERE order_id=? ORDER BY id');
    $items->execute([$orderId]);
    $sent = $db->prepare("SELECT ki.order_item_id,SUM(CASE WHEN ki.action='ADDED' THEN ki.quantity ELSE -ki.quantity END) sent_qty FROM pos_kot_items ki JOIN pos_kots k ON k.id=ki.kot_id WHERE k.order_id=? AND k.restaurant_id=? GROUP BY ki.order_item_id");
    $sent->execute([$orderId, $restaurantId]);
    $previous = [];
    foreach ($sent as $row) $previous[(int)$row['order_item_id']] = (int)$row['sent_qty'];
    $changes = [];
    foreach ($items as $item) {
        $id = (int)$item['id'];
        $delta = (int)$item['quantity'] - ($previous[$id] ?? 0);
        if ($delta !== 0) $changes[] = [$item, $delta];
        unset($previous[$id]);
    }
    // Deleted items are kept as zero-quantity rows until cancellation is sent.
    if (!$changes) return null;
    $number = 'KOT-'.strtoupper(bin2hex(random_bytes(6)));
    $insert = $db->prepare('INSERT INTO pos_kots(restaurant_id,order_id,kot_number,created_by,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())');
    $insert->execute([$restaurantId, $orderId, $number, $userId]);
    $kotId = (int)$db->lastInsertId();
    $line = $db->prepare('INSERT INTO pos_kot_items(kot_id,order_item_id,item_name,portion,quantity,action,special_instruction) VALUES(?,?,?,?,?,?,?)');
    foreach ($changes as [$item, $delta]) {
        $line->execute([$kotId, $item['id'], $item['item_name'], $item['portion'], abs($delta), $delta > 0 ? 'ADDED' : 'CANCELLED', $item['special_instruction']]);
    }
    return $kotId;
}
function pos_kot_pending(PDO $db, int $orderId, int $restaurantId): bool {
    $items=$db->prepare('SELECT id,quantity FROM order_items WHERE order_id=? ORDER BY id');
    $items->execute([$orderId]);
    $sent=$db->prepare("SELECT ki.order_item_id,SUM(CASE WHEN ki.action='ADDED' THEN ki.quantity ELSE -ki.quantity END) sent_qty FROM pos_kot_items ki JOIN pos_kots k ON k.id=ki.kot_id WHERE k.order_id=? AND k.restaurant_id=? GROUP BY ki.order_item_id");
    $sent->execute([$orderId,$restaurantId]);
    $previous=[];
    foreach($sent as $row)$previous[(int)$row['order_item_id']]=(int)$row['sent_qty'];
    foreach($items as $item){$id=(int)$item['id'];if((int)$item['quantity']!==($previous[$id]??0))return true;unset($previous[$id]);}
    return (bool)array_filter($previous,static fn(int $quantity):bool=>$quantity!==0);
}
