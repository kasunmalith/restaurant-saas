<?php
declare(strict_types=1);

function cashier_dashboard_menu_options(): array
{
    return [
        'Orders' => [
            'orders-all' => 'All Orders',
            'orders-dine-in' => 'Dine-In',
            'orders-takeaway' => 'Takeaway',
            'orders-delivery' => 'Delivery',
        ],
        'POS / Billing' => [
            'pos-main' => 'POS',
            'pos-kot' => 'Kitchen Order Tickets (KOT)',
            'pos-paid-bills' => 'Paid Bills',
            'pos-pending-bills' => 'Pending Bills',
        ],
        'Menu Management' => [
            'online-menu-link' => 'Online Menu Link',
            'menu-items' => 'Menu Items',
            'menu-categories' => 'Menu Categories',
        ],
        'Tables & QR Codes' => [
            'tables' => 'Tables',
            'qr-codes' => 'QR Codes',
            'table-history' => 'Table History',
        ],
        'Customers' => [
            'customers' => 'Customers',
        ],
        'Settings' => [
            'settings' => 'Settings',
        ],
    ];
}
