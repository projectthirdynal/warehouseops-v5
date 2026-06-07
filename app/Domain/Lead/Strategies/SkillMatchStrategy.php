<?php

declare(strict_types=1);

namespace App\Domain\Lead\Strategies;

use App\Domain\Lead\Contracts\AllocationStrategy;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use Illuminate\Support\Collection;

class SkillMatchStrategy implements AllocationStrategy
{
    public function score(Lead $lead, Collection $agents, array $formula): Collection
    {
        return $agents->map(function (AgentProfile $agent) use ($lead) {
            $score = 0.0;

            if ($lead->product_name) {
                $leadProduct = strtoupper($lead->product_name);
                $matched = false;

                if (! empty($agent->product_skills)) {
                    foreach ($agent->product_skills as $skill) {
                        if (strtoupper($skill) === $leadProduct || str_contains($leadProduct, strtoupper($skill))) {
                            $score = 1.0;
                            $matched = true;
                            break;
                        }
                    }
                }

                if (! $matched && ! empty($agent->category_skills)) {
                    $score = 0.5;
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
        return 'Skill Match';
    }
}
