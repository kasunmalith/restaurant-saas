<?php
declare(strict_types=1);

function pos_receipt_items_html(array $lines, int $template): string
{
    $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $compact = $template === 2;
    $html = $compact
        ? '<div class="rule"></div><table><thead><tr><th>Item</th><th class="right">Amount</th></tr></thead><tbody>'
        : '<div class="rule"></div><table><thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Unit</th><th class="right">Amount</th></tr></thead><tbody>';
    foreach ($lines as $line) {
        $name = ($compact ? (int)$line['quantity'].'x ' : '').$escape($line['item_name']);
        $html .= '<tr><td>'.$name
            .($line['portion'] ? ' ('.$escape($line['portion']).')' : '')
            .($line['special_instruction'] ? '<small>'.$escape($line['special_instruction']).'</small>' : '')
            .'</td>';
        if (!$compact) {
            $html .= '<td class="right">'.(int)$line['quantity'].'</td><td class="right">'.$escape($line['unit_price']).'</td>';
        }
        $html .= '<td class="right">'.$escape($line['line_total']).'</td></tr>';
    }
    return $html.'</tbody></table><div class="rule"></div>';
}
