<?php

namespace App\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
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
     * @param array<int> $leadIds
     * @param array<int> $agentIds
     * @param int $supervisorId
     * @return array{total_distributed: int, agent_count: int, per_agent: int}
     */
    public function distributeEqual(array $leadIds, array $agentIds, int $supervisorId): array
    {
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
     * @param array<int> $leadIds
     * @param array<int, int> $distribution Agent ID => count mapping
     * @param int $supervisorId
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

        DB::transaction(function () use ($leads, $distribution, $supervisorId, &$totalDistributed, &$leadIndex) {
            foreach ($distribution as $agentId => $count) {
                $agent = User::find($agentId);

                for ($i = 0; $i < $count && $leadIndex < $leads->count(); $i++) {
                    $lead = $leads[$leadIndex];

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

                    // Audit log
                    $this->auditService->log(
                        lead: $lead,
                        action: 'DISTRIBUTED',
                        user: User::find($supervisorId),
                        cycle: $cycle,
                        metadata: [
                            'agent_id' => $agentId,
                            'agent_name' => $agent->name,
                            'cycle_number' => $cycleNumber,
                        ]
                    );

                    $totalDistributed++;
                    $leadIndex++;
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
}
