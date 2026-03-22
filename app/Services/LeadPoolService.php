<?php

namespace App\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class LeadPoolService
{
    public function __construct(
        private LeadAuditService $auditService
    ) {}

    public function getAvailableLeads(?array $filters = null): Collection
    {
        $query = Lead::available()->with('customer');

        if ($filters) {
            if (isset($filters['source'])) {
                $query->where('source', $filters['source']);
            }
            if (isset($filters['city'])) {
                $query->where('city', $filters['city']);
            }
            if (isset($filters['product_name'])) {
                $query->where('product_name', 'ILIKE', "%{$filters['product_name']}%");
            }
        }

        return $query->orderBy('created_at', 'asc')->get();
    }

    public function getPoolStats(): array
    {
        return [
            'available' => Lead::available()->count(),
            'assigned' => Lead::assigned()->count(),
            'cooldown' => Lead::inCooldown()->count(),
            'exhausted' => Lead::exhausted()->count(),
        ];
    }

    public function markAsAssigned(Lead $lead, User $agent): void
    {
        $oldStatus = $lead->pool_status?->value;

        $lead->update([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: $oldStatus,
            newValue: PoolStatus::ASSIGNED->value,
            metadata: ['agent_id' => $agent->id]
        );
    }

    public function markAsCooldown(Lead $lead, int $cooldownHours): void
    {
        $oldStatus = $lead->pool_status?->value;

        $lead->update([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->addHours($cooldownHours),
            'assigned_to' => null,
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: $oldStatus,
            newValue: PoolStatus::COOLDOWN->value,
            metadata: ['cooldown_hours' => $cooldownHours]
        );
    }

    public function markAsAvailable(Lead $lead): void
    {
        $oldStatus = $lead->pool_status?->value;

        $lead->update([
            'pool_status' => PoolStatus::AVAILABLE,
            'cooldown_until' => null,
            'assigned_to' => null,
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: $oldStatus,
            newValue: PoolStatus::AVAILABLE->value
        );
    }

    public function markAsExhausted(Lead $lead): void
    {
        $oldStatus = $lead->pool_status?->value;

        $lead->update([
            'pool_status' => PoolStatus::EXHAUSTED,
            'is_exhausted' => true,
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: $oldStatus,
            newValue: PoolStatus::EXHAUSTED->value
        );
    }
}
