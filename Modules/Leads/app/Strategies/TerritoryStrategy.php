<?php

declare(strict_types=1);

namespace Modules\Leads\Strategies;

use App\Models\AgentProfile;
use Illuminate\Support\Collection;
use Modules\Leads\Contracts\AllocationStrategy;
use Modules\Leads\Models\Lead;

class TerritoryStrategy implements AllocationStrategy
{
    public function score(Lead $lead, Collection $agents, array $formula): Collection
    {
        return $agents->map(function (AgentProfile $agent) use ($lead) {
            $score = 0.0;

            if (! empty($agent->regions)) {
                $leadRegions = array_filter([$lead->state, $lead->city, $lead->barangay]);

                foreach ($agent->regions as $region) {
                    $regionUpper = strtoupper($region);
                    foreach ($leadRegions as $leadRegion) {
                        if ($leadRegion && strtoupper($leadRegion) === $regionUpper) {
                            $score = 1.0;
                            break 2;
                        }
                    }
                }
            }

            return [
                'agent_id' => $agent->user_id,
                'score' => round($score, 4),
            ];
        })->sortByDesc('score')->values();
    }

    public function name(): string
    {
        return 'Territory';
    }
}
