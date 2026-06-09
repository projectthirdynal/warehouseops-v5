<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\AgentWorkload;

class CapacityManager
{
    /**
     * Check if an agent can receive another lead right now.
     */
    public function canAcceptLead(int $agentId): bool
    {
        $profile = AgentProfile::where('user_id', $agentId)->first();
        if (! $profile) {
            return false;
        }

        if (! $profile->auto_assign_enabled || ! $profile->is_available) {
            return false;
        }

        $workload = AgentWorkload::firstOrCreate(
            ['agent_id' => $agentId],
            ['active_leads_count' => 0, 'today_assigned_count' => 0, 'today_converted_count' => 0]
        );

        $maxCycles = $profile->concurrent_lead_cap ?? $profile->max_active_cycles;
        if ($workload->isAtCapacity($maxCycles)) {
            return false;
        }

        if ($workload->isDailyCapReached($profile->max_daily_leads)) {
            return false;
        }

        return true;
    }

    /**
     * Get all agent IDs that are currently eligible for new leads.
     *
     * @return array<int>
     */
    public function getEligibleAgentIds(): array
    {
        $profiles = AgentProfile::where('is_available', true)
            ->where('auto_assign_enabled', true)
            ->get();

        $agentIds = $profiles->pluck('user_id')->all();

        // Pre-fetch all workloads in one query to avoid N+1 (BUG-15)
        $workloads = AgentWorkload::whereIn('agent_id', $agentIds)
            ->get()
            ->keyBy('agent_id');

        $eligible = [];
        foreach ($profiles as $profile) {
            $workload = $workloads->get($profile->user_id)
                ?? new AgentWorkload(['agent_id' => $profile->user_id, 'active_leads_count' => 0, 'today_assigned_count' => 0]);

            // Apply same stale-date reset as recordAssignment() so yesterday's count
            // doesn't block agents eligible for today (ISSUE-D).
            $effectiveCount = ($workload->last_assigned_at && ! $workload->last_assigned_at->isToday())
                ? 0
                : $workload->today_assigned_count;

            $maxCycles = $profile->concurrent_lead_cap ?? $profile->max_active_cycles;
            if ($workload->isAtCapacity($maxCycles)) {
                continue;
            }

            if ($effectiveCount >= $profile->max_daily_leads) {
                continue;
            }

            $eligible[] = $profile->user_id;
        }

        return $eligible;
    }

    /**
     * Record that a lead was assigned to an agent.
     */
    public function recordAssignment(int $agentId): void
    {
        $workload = AgentWorkload::firstOrCreate(
            ['agent_id' => $agentId],
            ['active_leads_count' => 0, 'today_assigned_count' => 0, 'today_converted_count' => 0]
        );

        $workload->recordAssignment();
    }

    /**
     * Record that a cycle was closed (agent freed up).
     */
    public function recordCycleClose(int $agentId): void
    {
        $workload = AgentWorkload::where('agent_id', $agentId)->first();
        if ($workload) {
            $workload->recordCycleClose();
        }
    }
}
