<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Users
            ['key' => 'users.view',      'label' => 'View Users',       'section' => 'Users', 'description' => 'Access user list and profiles'],
            ['key' => 'users.create',    'label' => 'Create Users',     'section' => 'Users', 'description' => 'Add new users to the system'],
            ['key' => 'users.edit',      'label' => 'Edit Users',       'section' => 'Users', 'description' => 'Modify user details and roles'],
            ['key' => 'users.delete',    'label' => 'Delete Users',     'section' => 'Users', 'description' => 'Remove users from the system'],
            ['key' => 'users.toggle',    'label' => 'Activate/Deactivate', 'section' => 'Users', 'description' => 'Enable or disable user accounts'],

            // Roles & Permissions
            ['key' => 'roles.view',      'label' => 'View Roles',       'section' => 'Roles', 'description' => 'View role definitions and permissions'],
            ['key' => 'roles.manage',    'label' => 'Manage Roles',     'section' => 'Roles', 'description' => 'Assign and revoke permissions'],

            // Inventory
            ['key' => 'inventory.view',  'label' => 'View Inventory',   'section' => 'Inventory', 'description' => 'Access inventory dashboard and data'],
            ['key' => 'inventory.edit',  'label' => 'Edit Inventory',   'section' => 'Inventory', 'description' => 'Modify stock levels and products'],
            ['key' => 'inventory.scan',  'label' => 'Scan Barcodes',    'section' => 'Inventory', 'description' => 'Use barcode scanner functionality'],
            ['key' => 'inventory.adjust','label' => 'Stock Adjustments','section' => 'Inventory', 'description' => 'Submit and approve stock adjustments'],

            // Procurement
            ['key' => 'procurement.view','label' => 'View Procurement', 'section' => 'Procurement', 'description' => 'View suppliers and purchase orders'],
            ['key' => 'procurement.edit','label' => 'Edit Procurement', 'section' => 'Procurement', 'description' => 'Create and modify purchase orders'],

            // Finance
            ['key' => 'finance.view',    'label' => 'View Finance',     'section' => 'Finance', 'description' => 'Access financial reports and data'],
            ['key' => 'finance.edit',    'label' => 'Edit Finance',     'section' => 'Finance', 'description' => 'Modify financial records'],

            // Settings
            ['key' => 'settings.view',   'label' => 'View Settings',    'section' => 'Settings', 'description' => 'Access system settings'],
            ['key' => 'settings.edit',   'label' => 'Edit Settings',    'section' => 'Settings', 'description' => 'Modify system configuration'],

            // Reports
            ['key' => 'reports.view',    'label' => 'View Reports',     'section' => 'Reports', 'description' => 'Access analytics and reports'],
            ['key' => 'reports.export',  'label' => 'Export Reports',   'section' => 'Reports', 'description' => 'Download and export report data'],

            // Activity Logs
            ['key' => 'logs.view',       'label' => 'View Activity Logs', 'section' => 'Audit', 'description' => 'View system activity and audit trails'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['key' => $perm['key']], $perm);
        }

        // Seed default role permissions
        $this->seedRolePermissions();
    }

    private function seedRolePermissions(): void
    {
        $roleMap = [
            'superadmin' => Permission::pluck('id')->all(),
            'admin' => Permission::whereIn('key', [
                'users.view', 'users.create', 'users.edit', 'users.toggle',
                'roles.view', 'roles.manage',
                'inventory.view', 'inventory.edit', 'inventory.scan', 'inventory.adjust',
                'procurement.view', 'procurement.edit',
                'finance.view', 'finance.edit',
                'settings.view', 'settings.edit',
                'reports.view', 'reports.export',
                'logs.view',
            ])->pluck('id')->all(),
            'supervisor' => Permission::whereIn('key', [
                'users.view',
                'inventory.view', 'inventory.scan', 'inventory.adjust',
                'procurement.view',
                'finance.view',
                'reports.view',
            ])->pluck('id')->all(),
            'finance' => Permission::whereIn('key', [
                'finance.view', 'finance.edit',
                'reports.view', 'reports.export',
                'inventory.view',
            ])->pluck('id')->all(),
            'accounting' => Permission::whereIn('key', [
                'finance.view',
                'reports.view',
                'inventory.view',
            ])->pluck('id')->all(),
            'warehouse' => Permission::whereIn('key', [
                'inventory.view', 'inventory.edit', 'inventory.scan', 'inventory.adjust',
                'procurement.view',
                'reports.view',
            ])->pluck('id')->all(),
            'agent' => Permission::whereIn('key', [
                'inventory.view', 'inventory.scan',
                'reports.view',
            ])->pluck('id')->all(),
        ];

        foreach ($roleMap as $role => $permissionIds) {
            foreach ($permissionIds as $pid) {
                \App\Models\RolePermission::firstOrCreate(
                    ['role' => $role, 'permission_id' => $pid]
                );
            }
        }
    }
}
