<?php

declare(strict_types=1);

namespace App\Domain\Lead\Strategies;

use App\Domain\Lead\Contracts\AllocationStrategy;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Models\AgentWorkload;
use Illuminate\Support\Collection;

class RoundRobinStrategy implements AllocationStrategy
{
    public function score(Lead $lead, Collection $agents, array $formula): Collection
    {
        return $agents->map(function (AgentProfile $agent) {
            $workload = AgentWorkload::firstOrCreate(
                ['agent_id' => $agent->user_id],
                ['active_leads_count' => 0, 'today_assigned_count' => 0]
            );

            $hoursSince = $workload->last_assigned_at
                ? min(24, now()->diffInHours($workload->last_assigned_at))
                : 24;

            return [
                'agent_id' => $agent->user_id,
                'score' => round($hoursSince / 24, 4),
            ];
        })->sortByDesc('score')->values();
    }

    public function name(): string
    {
        return 'Round Robin';
    }
}
