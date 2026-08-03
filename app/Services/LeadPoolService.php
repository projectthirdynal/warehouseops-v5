<?php

namespace App\Services;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class LeadPoolService
{
    public function __construct(
        private LeadAuditService $auditService,
        private CapacityManager $capacityManager,
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
                $query->whereRaw('LOWER(product_name) LIKE ?', ['%'.mb_strtolower($filters['product_name']).'%']);
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

    /**
     * Check for pool capacity issues — low availability (not enough leads to
     * distribute) or overstocked (too many unassigned leads piling up).
     * Checks both the overall pool and per-source breakdowns.
     *
     * @return array<int, array{level: string, count: int, threshold: int, source: ?string}>
     */
    public function checkCapacityAlerts(): array
    {
        $lowThreshold = (int) SiteSetting::get('pool_low_threshold', 20);
        $highThreshold = (int) SiteSetting::get('pool_high_threshold', 500);
        $lowThresholdPerSource = (int) SiteSetting::get('pool_low_threshold_per_source', 5);
        $highThresholdPerSource = (int) SiteSetting::get('pool_high_threshold_per_source', 150);

        $alerts = [];

        $overallAvailable = Lead::available()->count();
        if ($overallAvailable < $lowThreshold) {
            $alerts[] = ['level' => 'low', 'count' => $overallAvailable, 'threshold' => $lowThreshold, 'source' => null];
        } elseif ($overallAvailable > $highThreshold) {
            $alerts[] = ['level' => 'high', 'count' => $overallAvailable, 'threshold' => $highThreshold, 'source' => null];
        }

        $bySource = Lead::available()
            ->selectRaw('source, COUNT(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        foreach ($bySource as $source => $total) {
            $sourceValue = $source instanceof LeadSource ? $source->value : (string) $source;
            $total = (int) $total;

            if ($total < $lowThresholdPerSource) {
                $alerts[] = ['level' => 'low', 'count' => $total, 'threshold' => $lowThresholdPerSource, 'source' => $sourceValue];
            } elseif ($total > $highThresholdPerSource) {
                $alerts[] = ['level' => 'high', 'count' => $total, 'threshold' => $highThresholdPerSource, 'source' => $sourceValue];
            }
        }

        return $alerts;
    }

    public function markAsAssigned(Lead $lead, User $agent): void
    {
        $oldStatus = $lead->pool_status?->value;

        $lead->update([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
            'assigned_at' => now(),
        ]);

        Cache::forget('lead_pool:stats');
        Cache::forget('lead_pool:stats:imported');
        Cache::forget('lead_pool:stats:all');

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

        // Free agent workload before nulling assignment (ISS-003)
        if ($lead->assigned_to) {
            $this->capacityManager->recordCycleClose($lead->assigned_to);
        }

        $lead->update([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->addHours($cooldownHours),
            'assigned_to' => null,
        ]);

        Cache::forget('lead_pool:stats');
        Cache::forget('lead_pool:stats:imported');
        Cache::forget('lead_pool:stats:all');

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

        // Free agent workload before nulling assignment (ISS-003)
        if ($lead->assigned_to) {
            $this->capacityManager->recordCycleClose($lead->assigned_to);
        }

        $lead->update([
            'pool_status' => PoolStatus::AVAILABLE,
            'cooldown_until' => null,
            'assigned_to' => null,
        ]);

        Cache::forget('lead_pool:stats');
        Cache::forget('lead_pool:stats:imported');
        Cache::forget('lead_pool:stats:all');

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

        Cache::forget('lead_pool:stats');
        Cache::forget('lead_pool:stats:imported');
        Cache::forget('lead_pool:stats:all');

        $this->auditService->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: $oldStatus,
            newValue: PoolStatus::EXHAUSTED->value
        );
    }
}
