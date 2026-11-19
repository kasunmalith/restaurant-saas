<?php
declare(strict_types=1);

function inventory_role_can(string $role, string $capability): bool
{
    $matrix = [
        'OWNER' => ['view', 'operate', 'manage_access', 'manage_settings', 'view_reports'],
        'INVENTORY_MANAGER' => ['view', 'operate', 'manage_settings', 'view_reports'],
        'ACCOUNTANT' => ['view', 'view_reports'],
    ];
    return in_array($capability, $matrix[$role] ?? [], true);
}

function inventory_require_capability(array $context, string $capability): void
{
    if (!inventory_role_can((string)$context['portal_role'], $capability)) {
        http_response_code(403);
        throw new RuntimeException('Your Inventory Portal role does not allow this action.');
    }
}
