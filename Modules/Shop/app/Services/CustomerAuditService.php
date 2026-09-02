<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use App\Models\Customer;
use App\Models\CustomerAuditLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Request;

class CustomerAuditService
{
    /**
     * Log a customer profile change.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function logChange(Customer $customer, string $action, array $before = [], array $after = [], ?string $field = null): CustomerAuditLog
    {
        return CustomerAuditLog::create([
            'customer_id' => $customer->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'field' => $field,
            'before_state' => $before ?: null,
            'after_state' => $after ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    /**
     * Log a simple action (no state diff).
     */
    public function logAction(Customer $customer, string $action, ?string $note = null): CustomerAuditLog
    {
        return CustomerAuditLog::create([
            'customer_id' => $customer->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'after_state' => $note ? ['note' => $note] : null,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    /**
     * Get audit logs for a customer.
     *
     * @return Collection<int, CustomerAuditLog>
     */
    public function getLogs(Customer $customer, int $limit = 50): Collection
    {
        return $customer->auditLogs()
            ->with('user:id,name')
            ->limit($limit)
            ->get();
    }
}
