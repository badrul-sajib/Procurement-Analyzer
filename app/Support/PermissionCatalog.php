<?php

namespace App\Support;

/**
 * Seed defaults for roles and permissions (docs/architecture.md §5).
 * Business code checks permissions only; roles are editable at runtime.
 */
final class PermissionCatalog
{
    public const SUPER_ADMIN = 'Super Admin';

    public const PERMISSIONS = [
        'dashboard.view',
        'analyzer.view',
        'requisitions.view', 'requisitions.upload', 'requisitions.update', 'requisitions.verify',
        'requisitions.approve', 'requisitions.cancel',
        'items.view', 'items.manage', 'master_data.manage',
        'vendors.view', 'vendors.manage',
        'purchases.view', 'purchases.create', 'purchases.update', 'purchases.approve', 'purchases.cancel',
        'deliveries.view', 'deliveries.create', 'deliveries.confirm', 'deliveries.reverse',
        'finance.view',
        'payments.view', 'payments.create', 'payments.allocate', 'payments.reverse',
        'reports.view', 'reports.financial', 'reports.export',
        'users.manage', 'roles.manage', 'activity_logs.view', 'settings.manage',
    ];

    public static function roles(): array
    {
        $viewer = ['dashboard.view', 'analyzer.view', 'requisitions.view', 'items.view', 'vendors.view',
            'purchases.view', 'deliveries.view', 'reports.view'];

        return [
            self::SUPER_ADMIN => [], // bypasses all checks via Gate::before
            'Admin' => array_values(array_diff(self::PERMISSIONS, ['roles.manage'])),
            'Requisition Officer' => ['dashboard.view', 'analyzer.view', 'requisitions.view', 'requisitions.upload',
                'requisitions.update', 'requisitions.verify', 'requisitions.cancel', 'items.view', 'items.manage',
                'reports.view', 'reports.export'],
            'Purchase Manager' => ['dashboard.view', 'analyzer.view', 'requisitions.view', 'requisitions.approve',
                'requisitions.cancel', 'items.view', 'vendors.view', 'vendors.manage', 'purchases.view',
                'purchases.create', 'purchases.update', 'purchases.approve', 'purchases.cancel', 'deliveries.view',
                'finance.view', 'reports.view', 'reports.financial', 'reports.export'],
            'Inventory Manager' => ['dashboard.view', 'analyzer.view', 'requisitions.view', 'items.view',
                'items.manage', 'master_data.manage', 'vendors.view', 'purchases.view', 'deliveries.view',
                'deliveries.create', 'deliveries.confirm', 'deliveries.reverse', 'reports.view', 'reports.export'],
            'Delivery / Receiving Officer' => ['dashboard.view', 'items.view', 'vendors.view', 'purchases.view',
                'deliveries.view', 'deliveries.create', 'deliveries.confirm'],
            'Finance Manager' => ['dashboard.view', 'analyzer.view', 'requisitions.view', 'items.view',
                'vendors.view', 'vendors.manage', 'purchases.view', 'deliveries.view', 'finance.view',
                'payments.view', 'payments.create', 'payments.allocate', 'payments.reverse', 'reports.view',
                'reports.financial', 'reports.export'],
            'Viewer' => $viewer,
        ];
    }
}
