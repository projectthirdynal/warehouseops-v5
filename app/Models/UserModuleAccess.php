<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserModuleAccess extends Model
{
    protected $fillable = ['user_id', 'module_key', 'granted'];

    protected $casts = ['granted' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Canonical module list — sections and modules matching the admin matrix UI.
     * Each module has a key, label, section, and the roles that have access by default.
     */
    public static function moduleDefinitions(): array
    {
        return [
            // Core
            ['key' => 'core.dashboard',       'label' => 'Dashboard',         'section' => 'Core',       'roles' => ['superadmin','admin','supervisor','finance','accounting','warehouse','agent']],
            ['key' => 'core.settings',         'label' => 'Settings',          'section' => 'Core',       'roles' => ['superadmin','admin','supervisor','finance','accounting','warehouse','agent']],

            // Tickets
            ['key' => 'tickets.view',          'label' => 'Tickets',           'section' => 'Tickets',    'roles' => ['superadmin','admin','supervisor','finance','accounting','warehouse']],

            // Inventory
            ['key' => 'inventory.dashboard',   'label' => 'Inventory...',      'section' => 'Inventory',  'roles' => ['superadmin','admin','supervisor','finance','accounting','warehouse']],
            ['key' => 'inventory.movements',   'label' => 'Movements',         'section' => 'Inventory',  'roles' => ['superadmin','admin','supervisor','finance','accounting','warehouse']],
            ['key' => 'inventory.adjustments', 'label' => 'Stock Ad...',       'section' => 'Inventory',  'roles' => ['superadmin','admin','supervisor','finance','accounting','warehouse']],
            ['key' => 'inventory.supplies',    'label' => 'Supplies',          'section' => 'Inventory',  'roles' => ['superadmin','admin','supervisor','finance','accounting','warehouse']],
            ['key' => 'inventory.scanner',     'label' => 'Scanner',           'section' => 'Inventory',  'roles' => ['superadmin','admin','supervisor','warehouse']],
            ['key' => 'inventory.products',    'label' => 'Products',          'section' => 'Inventory',  'roles' => ['superadmin','admin','supervisor','warehouse']],
            ['key' => 'inventory.warehouses',  'label' => 'Warehouses...',     'section' => 'Inventory',  'roles' => ['superadmin','admin','supervisor','warehouse']],

            // Procurement
            ['key' => 'procurement.view',      'label' => 'Procurement...',    'section' => 'Procurement','roles' => ['superadmin','admin','supervisor','warehouse']],

            // Finance
            ['key' => 'finance.overview',      'label' => 'Finance ...',       'section' => 'Finance',    'roles' => ['superadmin','admin','supervisor','finance','accounting']],
            ['key' => 'finance.invoices',      'label' => 'Invoices...',       'section' => 'Finance',    'roles' => ['superadmin','admin','supervisor','finance','accounting']],
            ['key' => 'finance.quickbooks',    'label' => 'QuickBoo...',       'section' => 'Finance',    'roles' => ['superadmin','admin','supervisor','finance','accounting']],

            // Reports
            ['key' => 'reports.view',          'label' => 'Reports',           'section' => 'Reports',    'roles' => ['superadmin','admin','supervisor','finance','accounting','warehouse']],

            // CRM
            ['key' => 'crm.contacts',          'label' => 'CRM',               'section' => 'CRM',        'roles' => ['superadmin','admin','supervisor','finance','accounting']],

            // Shop / Ops
            ['key' => 'shop.pos',              'label' => 'Shop / P...',       'section' => 'Shop / Ops', 'roles' => ['superadmin','admin']],
            ['key' => 'logistics.waybills',    'label' => 'Waybills...',       'section' => 'Shop / Ops', 'roles' => ['superadmin','admin']],

            // Leads
            ['key' => 'leads.pool',            'label' => 'Leads / ...',       'section' => 'Leads',      'roles' => ['superadmin','admin','supervisor']],
            ['key' => 'leads.qc',              'label' => 'QC Revie...',       'section' => 'Leads',      'roles' => ['superadmin','admin','supervisor']],
            ['key' => 'leads.orders',          'label' => 'Orders',            'section' => 'Leads',      'roles' => ['superadmin','admin','supervisor']],
            ['key' => 'leads.sms',             'label' => 'SMS Camp...',       'section' => 'Leads',      'roles' => ['superadmin','admin']],

            // Admin
            ['key' => 'admin.panel',           'label' => 'Admin Pa...',       'section' => 'Admin',      'roles' => ['superadmin','admin']],
            ['key' => 'admin.agents',          'label' => 'Agents /...',       'section' => 'Admin',      'roles' => ['superadmin','admin']],
            ['key' => 'admin.monitoring',      'label' => 'Monitori...',       'section' => 'Admin',      'roles' => ['superadmin','admin']],

            // Agent Portal
            ['key' => 'agent.portal',          'label' => 'Agent Po...',       'section' => 'Agent Portal','roles' => ['agent']],
        ];
    }

    /**
     * Return the default granted module keys for a given role.
     */
    public static function defaultsForRole(string $role): array
    {
        return collect(static::moduleDefinitions())
            ->filter(fn($m) => in_array($role, $m['roles']))
            ->pluck('key')
            ->values()
            ->all();
    }
}
