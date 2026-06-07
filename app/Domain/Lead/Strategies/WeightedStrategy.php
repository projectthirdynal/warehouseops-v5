<?php

declare(strict_types=1);

namespace App\Domain\Lead\Strategies;

use App\Domain\Lead\Contracts\AllocationStrategy;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Models\AgentWorkload;
use Illuminate\Support\Collection;

class WeightedStrategy implements AllocationStrategy
{
    public function score(Lead $lead, Collection $agents, array $formula): Collection
    {
        return $agents->map(function (AgentProfile $agent) use ($formula) {
            $workload = AgentWorkload::firstOrCreate(
                ['agent_id' => $agent->user_id],
                ['active_leads_count' => 0, 'today_assigned_count' => 0]
            );

            $perfNormalized = ($agent->performance_score ?? 50) / 100;
            $maxCycles = $agent->concurrent_lead_cap ?? $agent->max_active_cycles;
            $loadFactor = $maxCycles > 0
                ? min(1, $workload->active_leads_count / $maxCycles)
                : 0;

            $wPerf = $formula['w_perf'] ?? 0.40;
            $wLoad = $formula['w_load'] ?? 0.30;
            $wTime = $formula['w_time'] ?? 0.30;

            $hoursSince = $workload->last_assigned_at
                ? min(24, now()->diffInHours($workload->last_assigned_at))
                : 24;

            $score = ($wPerf * $perfNormalized)
                   + ($wLoad * (1 - $loadFactor))
                   + ($wTime * ($hoursSince / 24));

            $score *= ($agent->distribution_weight ?? 1.0);

            return [
                'agent_id' => $agent->user_id,
                'score' => round($score, 4),
            ];
        })->sortByDesc('score')->values();
    }

    public function name(): string
    {
        return 'Weighted';
    }
}
