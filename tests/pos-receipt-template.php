<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/app/pos-receipt-template.php';

function receipt_test_assert(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

$lines = [
    ['item_name' => 'Tomato Soup', 'portion' => '', 'quantity' => 2, 'unit_price' => '620.00', 'line_total' => '1240.00', 'special_instruction' => 'Less salt'],
    ['item_name' => 'Rice & <Chicken>', 'portion' => 'Full', 'quantity' => 1, 'unit_price' => '1000.00', 'line_total' => '1000.00', 'special_instruction' => ''],
];
$original = pos_receipt_items_html($lines, 1);
$compact = pos_receipt_items_html($lines, 2);
receipt_test_assert(str_contains($original, '<th class="right">Qty</th><th class="right">Unit</th>'), 'Template 1 columns changed.');
receipt_test_assert(str_contains($original, '<td>Tomato Soup<small>Less salt</small></td><td class="right">2</td><td class="right">620.00</td><td class="right">1240.00</td>'), 'Template 1 row changed.');
receipt_test_assert(str_contains($compact, '<th>Item</th><th class="right">Amount</th>'), 'Template 2 columns are wrong.');
receipt_test_assert(!str_contains($compact, '<th class="right">Qty</th>') && !str_contains($compact, '<th class="right">Unit</th>') && !str_contains($compact, '620.00'), 'Template 2 still shows quantity or unit price columns.');
receipt_test_assert(str_contains($compact, '<td>2x Tomato Soup<small>Less salt</small></td><td class="right">1240.00</td>'), 'Template 2 did not prefix quantity or retain line amount.');
receipt_test_assert(str_contains($compact, '1x Rice &amp; &lt;Chicken&gt; (Full)'), 'Template 2 lost portion details or HTML escaping.');
echo "PASS: Template 1 unchanged; Template 2 prefixes quantity, hides Qty/Unit columns, retains amount and notes, and escapes item names.\n";
