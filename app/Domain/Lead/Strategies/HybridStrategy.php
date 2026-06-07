<?php

declare(strict_types=1);

namespace App\Domain\Lead\Strategies;

use App\Domain\Lead\Contracts\AllocationStrategy;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Models\AgentWorkload;
use Illuminate\Support\Collection;

class HybridStrategy implements AllocationStrategy
{
    public function score(Lead $lead, Collection $agents, array $formula): Collection
    {
        $wPerf = $formula['w_perf'] ?? 0.30;
        $wAvail = $formula['w_avail'] ?? 0.25;
        $wSkill = $formula['w_skill'] ?? 0.20;
        $wReg = $formula['w_reg'] ?? 0.15;
        $wLoad = $formula['w_load'] ?? 0.05;
        $wTime = $formula['w_time'] ?? 0.05;

        return $agents->map(function (AgentProfile $agent) use ($lead, $wPerf, $wAvail, $wSkill, $wReg, $wLoad, $wTime) {
            $workload = AgentWorkload::firstOrCreate(
                ['agent_id' => $agent->user_id],
                ['active_leads_count' => 0, 'today_assigned_count' => 0]
            );

            // Performance
            $perfNormalized = ($agent->performance_score ?? 50) / 100;

            // Availability (always within shift at this point, filtered earlier)
            $availFactor = 1.0;

            // Skill match
            $skillMatch = 0.0;
            if ($lead->product_name) {
                $leadProduct = strtoupper($lead->product_name);
                if (! empty($agent->product_skills)) {
                    foreach ($agent->product_skills as $skill) {
                        if (strtoupper($skill) === $leadProduct || str_contains($leadProduct, strtoupper($skill))) {
                            $skillMatch = 1.0;
                            break;
                        }
                    }
                }
                if ($skillMatch === 0.0 && ! empty($agent->category_skills)) {
                    $skillMatch = 0.5;
                }
            }

            // Region match
            $regionMatch = 0.0;
            if (! empty($agent->regions)) {
                $leadRegions = array_filter([$lead->state, $lead->city, $lead->barangay]);
                foreach ($agent->regions as $region) {
                    $regionUpper = strtoupper($region);
                    foreach ($leadRegions as $leadRegion) {
                        if ($leadRegion && strtoupper($leadRegion) === $regionUpper) {
                            $regionMatch = 1.0;
                            break 2;
                        }
                    }
                }
            }

            // Load factor (inverse)
            $maxCycles = $agent->concurrent_lead_cap ?? $agent->max_active_cycles;
            $loadFactor = $maxCycles > 0
                ? min(1, $workload->active_leads_count / $maxCycles)
                : 0;

            // Recency
            $hoursSince = $workload->last_assigned_at
                ? min(24, now()->diffInHours($workload->last_assigned_at))
                : 24;

            $score = ($wPerf * $perfNormalized)
                   + ($wAvail * $availFactor)
                   + ($wSkill * $skillMatch)
                   + ($wReg * $regionMatch)
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
        return 'Hybrid';
    }
}
