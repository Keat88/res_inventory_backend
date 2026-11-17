<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'branches.view', 'branches.manage',
            'employees.view', 'employees.manage',
            'categories.view', 'categories.manage',
            'menu.view', 'menu.manage',
            'customers.view', 'customers.manage',
            'tables.view', 'tables.manage',
            'orders.view', 'orders.create', 'orders.update', 'orders.cancel',
            'payments.view', 'payments.create', 'payments.confirm', 'payments.refund',
            'kitchen.view', 'kitchen.update',
            'inventory.view', 'inventory.manage', 'inventory.adjust',
            'recipes.view', 'recipes.manage',
            'suppliers.view', 'suppliers.manage',
            'purchase_orders.view', 'purchase_orders.manage', 'purchase_orders.approve', 'purchase_orders.receive',
            'stock_transfers.view', 'stock_transfers.manage', 'stock_transfers.dispatch', 'stock_transfers.receive',
            'shifts.view', 'shifts.open', 'shifts.manage',
            'discounts.view', 'discounts.manage', 'discounts.authorize',
            'reports.view',
            'audit_logs.view',
            'settings.view', 'settings.manage',
            'receipts.view', 'receipts.create',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $all = $permissions;

        $manager = array_values(array_diff($all, [
            'branches.manage',
            'settings.manage',
        ]));

        $inventory = [
            'branches.view', 'menu.view', 'inventory.view', 'inventory.manage', 'inventory.adjust',
            'recipes.view', 'recipes.manage', 'suppliers.view', 'suppliers.manage',
            'purchase_orders.view', 'purchase_orders.manage', 'purchase_orders.approve', 'purchase_orders.receive',
            'stock_transfers.view', 'stock_transfers.manage', 'stock_transfers.dispatch', 'stock_transfers.receive',
            'reports.view', 'audit_logs.view', 'settings.view',
        ];

        $cashier = [
            'branches.view', 'categories.view', 'menu.view',
            'customers.view', 'customers.manage',
            'tables.view', 'tables.manage',
            'orders.view', 'orders.create', 'orders.update', 'orders.cancel',
            'payments.view', 'payments.create', 'payments.confirm',
            'kitchen.view',
            'shifts.view', 'shifts.open',
            'discounts.view', 'discounts.authorize',
            'receipts.view', 'receipts.create',
        ];

        foreach ([
            'ADMIN' => $all,
            'MANAGER' => $manager,
            'INVENTORY_STAFF' => $inventory,
            'CASHIER' => $cashier,
        ] as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
