<?php

namespace App\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Models\AgentWorkload;
use App\Models\LeadCycle;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkloadBalancingService
{
    public function __construct(
        private CapacityManager $capacityManager,
        private LeadDistributionService $distributionService,
        private LeadAuditService $auditService
    ) {}

    /**
     * Detect agents that are currently overloaded based on their workload
     * versus their configured capacity limits.
     *
     * @return Collection<int, array{
     *   agent_id: int,
     *   agent_name: string,
     *   active_leads: int,
     *   today_assigned: int,
     *   max_concurrent: int,
     *   max_daily: int,
     *   overload_reason: string,
     *   overflow_enabled: bool,
     * }>
     */
    public function detectOverloadedAgents(): Collection
    {
        $profiles = AgentProfile::where('is_available', true)
            ->where('auto_assign_enabled', true)
            ->with('user')
            ->get();

        $agentIds = $profiles->pluck('user_id')->all();
        $workloads = AgentWorkload::whereIn('agent_id', $agentIds)
            ->get()
            ->keyBy('agent_id');

        $activeLeadCounts = Lead::whereIn('assigned_to', $agentIds)
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->selectRaw('assigned_to, count(*) as count')
            ->groupBy('assigned_to')
            ->pluck('count', 'assigned_to');

        $overloaded = collect();

        foreach ($profiles as $profile) {
            $workload = $workloads->get($profile->user_id);
            $activeLeads = $activeLeadCounts[$profile->user_id] ?? 0;
            $todayAssigned = $workload?->today_assigned_count ?? 0;

            // Reset daily counter if stale
            if ($workload?->last_assigned_at && ! $workload->last_assigned_at->isToday()) {
                $todayAssigned = 0;
            }

            $maxConcurrent = $profile->concurrent_lead_cap ?? $profile->max_active_cycles;
            $maxDaily = $profile->max_daily_leads ?? 50;

            $reasons = [];

            if ($activeLeads > $maxConcurrent) {
                $reasons[] = "active_leads ({$activeLeads}) > concurrent_cap ({$maxConcurrent})";
            }

            if ($todayAssigned > $maxDaily) {
                $reasons[] = "today_assigned ({$todayAssigned}) > max_daily ({$maxDaily})";
            }

            if (! empty($reasons)) {
                $overloaded->push([
                    'agent_id' => $profile->user_id,
                    'agent_name' => $profile->user?->name ?? "Agent #{$profile->user_id}",
                    'active_leads' => $activeLeads,
                    'today_assigned' => $todayAssigned,
                    'max_concurrent' => $maxConcurrent,
                    'max_daily' => $maxDaily,
                    'overload_reason' => implode('; ', $reasons),
                    'overflow_enabled' => (bool) $profile->overflow_enabled,
                ]);
            }
        }

        return $overloaded;
    }

    /**
     * Get a comprehensive workload snapshot for all active agents.
     *
     * @return array{
     *   agents: Collection<int, array>,
     *   overloaded_count: int,
     *   total_active_leads: int,
     *   total_capacity: int,
     *   available_capacity: int,
     * }
     */
    public function getWorkloadSnapshot(): array
    {
        $profiles = AgentProfile::with('user')
            ->whereHas('user', fn ($q) => $q->where('role', 'agent')->where('is_active', true))
            ->get();

        $agentIds = $profiles->pluck('user_id')->all();
        $workloads = AgentWorkload::whereIn('agent_id', $agentIds)
            ->get()
            ->keyBy('agent_id');

        $activeLeadCounts = Lead::whereIn('assigned_to', $agentIds)
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->selectRaw('assigned_to, count(*) as count')
            ->groupBy('assigned_to')
            ->pluck('count', 'assigned_to');

        $agents = $profiles->map(function ($profile) use ($workloads, $activeLeadCounts) {
            $workload = $workloads->get($profile->user_id);
            $activeLeads = $activeLeadCounts[$profile->user_id] ?? 0;
            $todayAssigned = $workload?->today_assigned_count ?? 0;

            if ($workload?->last_assigned_at && ! $workload->last_assigned_at->isToday()) {
                $todayAssigned = 0;
            }

            $maxConcurrent = $profile->concurrent_lead_cap ?? $profile->max_active_cycles;
            $maxDaily = $profile->max_daily_leads ?? 50;
            $utilization = $maxConcurrent > 0
                ? round(($activeLeads / $maxConcurrent) * 100, 1)
                : 0;

            return [
                'agent_id' => $profile->user_id,
                'agent_name' => $profile->user?->name ?? "Agent #{$profile->user_id}",
                'active_leads' => $activeLeads,
                'today_assigned' => $todayAssigned,
                'today_converted' => $workload?->today_converted_count ?? 0,
                'max_concurrent' => $maxConcurrent,
                'max_daily' => $maxDaily,
                'utilization_pct' => $utilization,
                'is_available' => (bool) $profile->is_available,
                'auto_assign_enabled' => (bool) $profile->auto_assign_enabled,
                'overflow_enabled' => (bool) $profile->overflow_enabled,
                'is_overloaded' => $activeLeads > $maxConcurrent || $todayAssigned > $maxDaily,
                'last_assigned_at' => $workload?->last_assigned_at?->toIso8601String(),
                'shift_start' => $profile->shift_start,
                'shift_end' => $profile->shift_end,
            ];
        })->sortByDesc('utilization_pct')->values();

        $totalActiveLeads = $agents->sum('active_leads');
        $totalCapacity = $agents->sum('max_concurrent');
        $availableCapacity = $agents->sum(fn ($a) => max(0, $a['max_concurrent'] - $a['active_leads']));
        $overloadedCount = $agents->filter(fn ($a) => $a['is_overloaded'])->count();

        return [
            'agents' => $agents,
            'overloaded_count' => $overloadedCount,
            'total_active_leads' => $totalActiveLeads,
            'total_capacity' => $totalCapacity,
            'available_capacity' => $availableCapacity,
        ];
    }

    /**
     * Auto-pause an overloaded agent (disable auto-assign).
     */
    public function pauseAgent(int $agentId, string $reason): void
    {
        $profile = AgentProfile::where('user_id', $agentId)->first();
        if ($profile) {
            $profile->update([
                'auto_assign_enabled' => false,
            ]);

            Log::info("WorkloadBalancing: paused agent #{$agentId} — {$reason}");
        }
    }

    /**
     * Re-enable auto-assign for a paused agent.
     */
    public function resumeAgent(int $agentId): void
    {
        $profile = AgentProfile::where('user_id', $agentId)->first();
        if ($profile) {
            $profile->update([
                'auto_assign_enabled' => true,
            ]);

            Log::info("WorkloadBalancing: resumed agent #{$agentId}");
        }
    }

    /**
     * Redistribute excess leads from an overloaded agent to agents with capacity.
     *
     * @param  int  $agentId  The overloaded agent
     * @param  int  $maxToRedistribute  Maximum leads to move (default: excess count)
     * @return array{redistributed: int, remaining: int, errors: array<int, string>}
     */
    public function redistributeFromAgent(int $agentId, int $maxToRedistribute = 0): array
    {
        $profile = AgentProfile::where('user_id', $agentId)->first();
        if (! $profile) {
            return ['redistributed' => 0, 'remaining' => 0, 'errors' => ["Agent #{$agentId} profile not found"]];
        }

        $maxConcurrent = $profile->concurrent_lead_cap ?? $profile->max_active_cycles;
        $activeLeads = Lead::where('assigned_to', $agentId)
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->count();

        $excess = $activeLeads - $maxConcurrent;
        if ($excess <= 0) {
            return ['redistributed' => 0, 'remaining' => $activeLeads, 'errors' => ['Agent is not overloaded']];
        }

        $toRedistribute = $maxToRedistribute > 0 ? min($maxToRedistribute, $excess) : $excess;

        // Get leads to redistribute — oldest first (longest waiting)
        $leadsToMove = Lead::where('assigned_to', $agentId)
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->orderBy('assigned_at')
            ->limit($toRedistribute)
            ->get();

        // Find agents with capacity
        $eligibleIds = $this->capacityManager->getEligibleAgentIds();
        $eligibleIds = array_values(array_filter($eligibleIds, fn ($id) => $id !== $agentId));

        if (empty($eligibleIds)) {
            return ['redistributed' => 0, 'remaining' => $activeLeads, 'errors' => ['No eligible agents with capacity to receive leads']];
        }

        $actor = User::where('role', 'superadmin')->first() ?? User::find(1);
        $redistributed = 0;
        $errors = [];
        $eligibleIdx = 0;

        foreach ($leadsToMove as $lead) {
            $targetAgentId = $eligibleIds[$eligibleIdx % count($eligibleIds)];
            $eligibleIdx++;

            try {
                $this->distributionService->reassign(
                    $lead,
                    User::find($targetAgentId),
                    'Workload balancing — auto-redistribute from overloaded agent',
                    $actor
                );
                $redistributed++;
            } catch (\Exception $e) {
                $errors[] = "Lead #{$lead->id}: {$e->getMessage()}";
            }
        }

        Log::info("WorkloadBalancing: redistributed {$redistributed} leads from agent #{$agentId}");

        return [
            'redistributed' => $redistributed,
            'remaining' => $activeLeads - $redistributed,
            'errors' => $errors,
        ];
    }

    /**
     * Run the full balancing cycle: detect overloaded agents,
     * redistribute their excess leads, and auto-pause if still overloaded.
     *
     * @return array{
     *   overloaded_detected: int,
     *   agents_paused: int,
     *   leads_redistributed: int,
     *   details: array,
     * }
     */
    public function runBalancingCycle(): array
    {
        $overloaded = $this->detectOverloadedAgents();
        $details = [];
        $agentsPaused = 0;
        $leadsRedistributed = 0;

        foreach ($overloaded as $agent) {
            // Skip agents with overflow enabled — they accept extra load intentionally
            if ($agent['overflow_enabled']) {
                $details[] = [
                    'agent_id' => $agent['agent_id'],
                    'agent_name' => $agent['agent_name'],
                    'action' => 'skipped (overflow enabled)',
                    'reason' => $agent['overload_reason'],
                ];
                continue;
            }

            // Redistribute excess leads
            $result = $this->redistributeFromAgent($agent['agent_id']);
            $leadsRedistributed += $result['redistributed'];

            // Re-check if still overloaded after redistribution
            $stillOverloaded = $this->detectOverloadedAgents()
                ->firstWhere('agent_id', $agent['agent_id']);

            if ($stillOverloaded) {
                $this->pauseAgent($agent['agent_id'], $agent['overload_reason']);
                $agentsPaused++;
                $details[] = [
                    'agent_id' => $agent['agent_id'],
                    'agent_name' => $agent['agent_name'],
                    'action' => 'paused + redistributed',
                    'redistributed' => $result['redistributed'],
                    'remaining' => $result['remaining'],
                    'reason' => $agent['overload_reason'],
                    'errors' => $result['errors'],
                ];
            } else {
                $details[] = [
                    'agent_id' => $agent['agent_id'],
                    'agent_name' => $agent['agent_name'],
                    'action' => 'redistributed',
                    'redistributed' => $result['redistributed'],
                    'reason' => $agent['overload_reason'],
                    'errors' => $result['errors'],
                ];
            }
        }

        return [
            'overloaded_detected' => $overloaded->count(),
            'agents_paused' => $agentsPaused,
            'leads_redistributed' => $leadsRedistributed,
            'details' => $details,
        ];
    }
}
