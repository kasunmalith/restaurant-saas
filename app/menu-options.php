<?php
declare(strict_types=1);

function menu_attach_options(PDO $db, int $restaurantId, array &$items): void {
    if (!$items) return;
    $byId=[];
    foreach ($items as $index=>$item) { $items[$index]['options']=[]; $byId[(int)$item['id']]=$index; }
    $query=$db->prepare('SELECT menu_item_id,name,price,is_default FROM menu_item_options WHERE restaurant_id=? ORDER BY menu_item_id,sort_order,id');
    $query->execute([$restaurantId]);
    foreach ($query as $row) if (isset($byId[(int)$row['menu_item_id']])) $items[$byId[(int)$row['menu_item_id']]]['options'][]=$row;
}

function menu_option_price(PDO $db, int $restaurantId, array $item, ?string $portion): string {
    $query=$db->prepare('SELECT name,price FROM menu_item_options WHERE restaurant_id=? AND menu_item_id=? ORDER BY sort_order,id');
    $query->execute([$restaurantId,(int)$item['id']]);
    $options=$query->fetchAll(PDO::FETCH_ASSOC);
    if ($options) {
        foreach ($options as $option) if ($option['name']===$portion) return (string)$option['price'];
        throw new InvalidArgumentException('Select a valid size for '.$item['name'].'.');
    }
    if ((int)$item['is_portioned']===1) {
        if (!in_array($portion,['HALF','FULL'],true)) throw new InvalidArgumentException('Select Half or Full.');
        $price=$portion==='HALF'?$item['half_price']:$item['full_price'];
    } else {
        if ($portion!==null) throw new InvalidArgumentException('This item has no size options.');
        $price=$item['price'];
    }
    if ($price===null) throw new InvalidArgumentException('The selected size is unavailable.');
    return (string)$price;
}
