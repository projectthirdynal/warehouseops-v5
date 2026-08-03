<?php

namespace App\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Events\LeadAssigned;
use App\Models\LeadCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class LeadDistributionService
{
    public function __construct(
        private LeadPoolService $poolService,
        private LeadAuditService $auditService
    ) {}

    /**
     * Distribute leads evenly among agents.
     *
     * @param  array<int>  $leadIds
     * @param  array<int>  $agentIds
     * @return array{total_distributed: int, agent_count: int, per_agent: int}
     */
    public function distributeEqual(array $leadIds, array $agentIds, int $supervisorId): array
    {
        if (empty($agentIds)) {
            return ['total_distributed' => 0, 'agent_count' => 0, 'per_agent' => 0];
        }

        $leadsPerAgent = (int) floor(count($leadIds) / count($agentIds));
        $distribution = array_fill_keys($agentIds, $leadsPerAgent);

        // Distribute remainder
        $remainder = count($leadIds) % count($agentIds);
        $i = 0;
        foreach ($distribution as $agentId => $count) {
            if ($i < $remainder) {
                $distribution[$agentId]++;
            }
            $i++;
        }

        return $this->distributeCustom($leadIds, $distribution, $supervisorId);
    }

    /**
     * Distribute leads according to custom distribution per agent.
     *
     * @param  array<int>  $leadIds
     * @param  array<int, int>  $distribution  Agent ID => count mapping
     * @return array{total_distributed: int, agent_count: int, per_agent: int}
     */
    public function distributeCustom(array $leadIds, array $distribution, int $supervisorId): array
    {
        $totalDistributed = 0;
        $leads = Lead::whereIn('id', $leadIds)
            ->where('pool_status', PoolStatus::AVAILABLE)
            ->get()
            ->shuffle();

        $leadIndex = 0;
        $supervisor = User::find($supervisorId);

        DB::transaction(function () use ($leads, $distribution, $supervisor, &$totalDistributed, &$leadIndex) {
            foreach ($distribution as $agentId => $count) {
                $agent = User::find($agentId);
                if (! $agent) {
                    continue; // Skip invalid agent IDs
                }

                $assigned = 0;
                while ($assigned < $count && $leadIndex < $leads->count()) {
                    $lead = $leads[$leadIndex];
                    $leadIndex++;

                    // Race-condition guard: skip leads claimed by another worker (ISS-016)
                    $lead->refresh();
                    if ($lead->pool_status !== PoolStatus::AVAILABLE) {
                        continue;
                    }

                    // Create new cycle
                    $cycleNumber = $lead->total_cycles + 1;
                    $cycle = LeadCycle::create([
                        'lead_id' => $lead->id,
                        'cycle_number' => $cycleNumber,
                        'assigned_agent_id' => $agentId,
                        'status' => 'ACTIVE',
                        'opened_at' => now(),
                    ]);

                    // Update lead
                    $lead->update([
                        'pool_status' => PoolStatus::ASSIGNED,
                        'assigned_to' => $agentId,
                        'assigned_at' => now(),
                        'total_cycles' => $cycleNumber,
                    ]);

                    // Update agent workload
                    app(CapacityManager::class)->recordAssignment($agentId);

                    // Audit log
                    $this->auditService->log(
                        lead: $lead,
                        action: 'DISTRIBUTED',
                        user: $supervisor,
                        cycle: $cycle,
                        metadata: [
                            'agent_id' => $agentId,
                            'agent_name' => $agent->name,
                            'cycle_number' => $cycleNumber,
                        ]
                    );

                    $assigned++;
                    $totalDistributed++;
                }
            }
        });

        return [
            'total_distributed' => $totalDistributed,
            'agent_count' => count($distribution),
            'per_agent' => $totalDistributed > 0 ? (int) ceil($totalDistributed / count($distribution)) : 0,
        ];
    }

    /**
     * Get all agents that are active and available for lead distribution.
     *
     * @return Collection<int, User>
     */
    public function getAvailableAgents(): Collection
    {
        return User::where('role', 'agent')
            ->where('is_active', true)
            ->whereHas('agentProfile', fn ($q) => $q->where('is_available', true))
            ->with('agentProfile')
            ->get();
    }

    /**
     * Reassign a single ASSIGNED lead to a different agent, closing the
     * current cycle and opening a new one.
     *
     * @return array{lead: Lead, cycle: LeadCycle}
     *
     * @throws \RuntimeException if the lead is not currently ASSIGNED
     */
    public function reassign(Lead $lead, User $agent, string $reason, User $actor): array
    {
        if ($lead->pool_status !== PoolStatus::ASSIGNED) {
            throw new \RuntimeException('Lead must be currently assigned before it can be reassigned.');
        }

        $oldAgent = $lead->assignedAgent;

        return DB::transaction(function () use ($lead, $agent, $oldAgent, $reason, $actor) {
            $existingCycle = $lead->activeCycle;
            if ($existingCycle) {
                $existingCycle->update([
                    'status' => 'CLOSED',
                    'outcome' => 'REASSIGNED',
                    'closed_at' => now(),
                ]);
                if ($oldAgent) {
                    app(CapacityManager::class)->recordCycleClose($oldAgent->id);
                }
            }

            $cycleNumber = $lead->total_cycles + 1;
            $cycle = LeadCycle::create([
                'lead_id' => $lead->id,
                'cycle_number' => $cycleNumber,
                'assigned_agent_id' => $agent->id,
                'status' => 'ACTIVE',
                'opened_at' => now(),
            ]);

            $lead->update([
                'pool_status' => PoolStatus::ASSIGNED,
                'assigned_to' => $agent->id,
                'assigned_at' => now(),
                'total_cycles' => $cycleNumber,
            ]);

            app(CapacityManager::class)->recordAssignment($agent->id);

            $this->auditService->log(
                lead: $lead,
                action: 'REASSIGNED',
                user: $actor,
                cycle: $cycle,
                metadata: [
                    'from_agent_id' => $oldAgent?->id,
                    'from_agent_name' => $oldAgent?->name,
                    'to_agent_id' => $agent->id,
                    'to_agent_name' => $agent->name,
                    'reason' => $reason,
                ]
            );

            LeadAssigned::dispatch($lead, $agent, $cycle, $reason);

            return ['lead' => $lead, 'cycle' => $cycle];
        });
    }

    /**
     * Reassign multiple leads to a single agent, reporting per-lead success/failure.
     *
     * @param  array<int>  $leadIds
     * @return array{reassigned: int, failed: int, errors: array<int, string>}
     */
    public function bulkReassign(array $leadIds, User $agent, string $reason, User $actor): array
    {
        $leads = Lead::whereIn('id', $leadIds)->get()->keyBy('id');
        $reassigned = 0;
        $errors = [];

        foreach ($leadIds as $leadId) {
            $lead = $leads->get($leadId);

            if (! $lead) {
                $errors[] = "Lead #{$leadId}: not found";

                continue;
            }

            try {
                $this->reassign($lead, $agent, $reason, $actor);
                $reassigned++;
            } catch (\RuntimeException $e) {
                $errors[] = "Lead #{$leadId} ({$lead->name}): {$e->getMessage()}";
            }
        }

        return [
            'reassigned' => $reassigned,
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }
}
