<?php

namespace App\Services;

use App\Domain\Lead\Enums\DistributionStrategy;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Models\AgentWorkload;
use App\Models\DistributionRule;
use App\Models\User;
use Illuminate\Support\Collection;

class DistributionEngine
{
    public function __construct(
        private CapacityManager $capacityManager,
        private AgentAvailability $agentAvailability,
        private LeadPoolService $poolService,
        private LeadAuditService $auditService,
    ) {}

    /**
     * Find the best agent for a lead using active rules.
     *
     * @return array{agent_id: ?int, rule_id: ?int, score: float, reason: string}
     */
    public function findBestAgent(Lead $lead): array
    {
        $rules = DistributionRule::active()->get();

        foreach ($rules as $rule) {
            $eligible = $this->filterEligibleAgents($lead, $rule);
            if ($eligible->isEmpty()) {
                continue;
            }

            $scored = $this->scoreAgents($lead, $eligible, $rule);
            $best = $scored->first();

            if ($best) {
                return [
                    'agent_id' => $best['agent_id'],
                    'rule_id' => $rule->id,
                    'score' => $best['score'],
                    'reason' => "Matched via rule '{$rule->name}' ({$rule->strategy->value})",
                ];
            }
        }

        // Fallback: round-robin across all capacity-eligible agents
        $eligible = $this->filterEligibleAgents($lead, null);
        if ($eligible->isNotEmpty()) {
            // True round-robin: cycle through eligible agents
            $agentIds = $eligible->pluck('user_id')->sort()->values()->all();
            $lastKey = \Illuminate\Support\Facades\Cache::get('distribution:last_round_robin_key', -1);
            $nextKey = ($lastKey + 1) % count($agentIds);
            $bestId = $agentIds[$nextKey];
            \Illuminate\Support\Facades\Cache::put('distribution:last_round_robin_key', $nextKey, now()->addDay());

            return [
                'agent_id' => $bestId,
                'rule_id' => null,
                'score' => 0.0,
                'reason' => 'Fallback round-robin (no matching rule)',
            ];
        }

        return [
            'agent_id' => null,
            'rule_id' => null,
            'score' => 0.0,
            'reason' => 'No eligible agents available',
        ];
    }

    /**
     * Filter agents that are eligible for this lead under the given rule.
     *
     * @param Lead $lead
     * @param DistributionRule|null $rule
     * @return Collection<int, AgentProfile>
     */
    public function filterEligibleAgents(Lead $lead, ?DistributionRule $rule): Collection
    {
        $query = AgentProfile::where('is_available', true)
            ->where('auto_assign_enabled', true)
            ->with('user');

        if ($rule && $rule->filters) {
            $filters = $rule->filters;

            if (! empty($filters['regions'])) {
                $query->where(function ($q) use ($filters) {
                    foreach ($filters['regions'] as $region) {
                        $q->orWhereJsonContains('regions', $region);
                    }
                });
            }

            if (! empty($filters['product_skills'])) {
                $query->where(function ($q) use ($filters) {
                    foreach ($filters['product_skills'] as $skill) {
                        $q->orWhereJsonContains('product_skills', $skill);
                    }
                });
            }

            if (! empty($filters['sources'])) {
                $query->where(function ($q) use ($filters) {
                    foreach ($filters['sources'] as $source) {
                        $q->orWhereJsonContains('preferred_lead_sources', $source);
                    }
                });
            }
        }

        $profiles = $query->get();

        return $profiles->filter(function (AgentProfile $profile) use ($lead) {
            // Capacity check
            if (! $this->capacityManager->canAcceptLead($profile->user_id)) {
                return false;
            }

            // Shift check
            if (! $this->agentAvailability->isWithinShift($profile->user_id)) {
                return false;
            }

            // Excluded region check
            if ($lead->state && $this->agentAvailability->hasExcludedRegion($profile->user_id, $lead->state)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Score and rank eligible agents for a lead.
     *
     * @param Lead $lead
     * @param Collection<int, AgentProfile> $agents
     * @param DistributionRule $rule
     * @return Collection<int, array{agent_id: int, score: float}>
     */
    public function scoreAgents(Lead $lead, Collection $agents, DistributionRule $rule): Collection
    {
        $formula = $rule->weight_formula ?? [
            'w_perf' => 0.30,
            'w_avail' => 0.25,
            'w_skill' => 0.20,
            'w_reg' => 0.15,
            'w_load' => 0.05,
            'w_time' => 0.05,
        ];

        $strategy = $rule->strategy;

        $scored = $agents->map(function (AgentProfile $agent) use ($lead, $formula, $strategy) {
            $score = 0.0;
            $workload = AgentWorkload::firstOrCreate(
                ['agent_id' => $agent->user_id],
                ['active_leads_count' => 0, 'today_assigned_count' => 0]
            );

            // Performance score (normalized 0–100)
            $wPerf = $formula['w_perf'] ?? 0.30;
            $perfNormalized = ($agent->performance_score ?? 50) / 100;
            $score += $wPerf * $perfNormalized;

            // Availability factor
            $wAvail = $formula['w_avail'] ?? 0.25;
            $availFactor = $this->agentAvailability->isWithinShift($agent->user_id) ? 1.0 : 0.0;
            $score += $wAvail * $availFactor;

            // Skill match
            $wSkill = $formula['w_skill'] ?? 0.20;
            $skillMatch = $this->computeSkillMatch($agent, $lead);
            $score += $wSkill * $skillMatch;

            // Region match
            $wReg = $formula['w_reg'] ?? 0.15;
            $regionMatch = $this->computeRegionMatch($agent, $lead);
            $score += $wReg * $regionMatch;

            // Load factor (inverse: lower load = higher score)
            $wLoad = $formula['w_load'] ?? 0.05;
            $maxCycles = $agent->concurrent_lead_cap ?? $agent->max_active_cycles;
            $loadFactor = $maxCycles > 0
                ? min(1, $workload->active_leads_count / $maxCycles)
                : 0;
            $score += $wLoad * (1 - $loadFactor);

            // Recency (time since last assignment)
            $wTime = $formula['w_time'] ?? 0.05;
            $hoursSince = $workload->last_assigned_at
                ? min(24, now()->diffInHours($workload->last_assigned_at))
                : 24;
            $timeFactor = $hoursSince / 24;
            $score += $wTime * $timeFactor;

            // Distribution weight multiplier
            $score *= ($agent->distribution_weight ?? 1.0);

            // Strategy blending: boost the dominant factor without discarding
            // capacity/availability guards already enforced in filterEligibleAgents
            if ($strategy === DistributionStrategy::ROUND_ROBIN) {
                $score = $score * 0.3 + $timeFactor * 0.7; // Recency-heavy
            } elseif ($strategy === DistributionStrategy::WEIGHTED) {
                $score = $score * 0.3 + ($perfNormalized * $availFactor * ($agent->distribution_weight ?? 1.0)) * 0.7;
            } elseif ($strategy === DistributionStrategy::SKILL_MATCH) {
                $score = $score * 0.3 + $skillMatch * 0.7;
            } elseif ($strategy === DistributionStrategy::TERRITORY) {
                $score = $score * 0.3 + $regionMatch * 0.7;
            }

            return [
                'agent_id' => $agent->user_id,
                'score' => round($score, 4),
                'profile' => $agent,
                'workload' => $workload,
            ];
        });

        return $scored->sortByDesc('score')->values();
    }

    /**
     * Compute skill match score (0–1) between agent and lead.
     */
    private function computeSkillMatch(AgentProfile $agent, Lead $lead): float
    {
        if (! $lead->product_name) {
            return 0.5; // Neutral when no product specified
        }

        $leadProduct = strtoupper($lead->product_name);

        // Exact product skill match (product_skills stores product IDs/SKUs from catalog)
        if (! empty($agent->product_skills)) {
            foreach ($agent->product_skills as $skill) {
                if (strtoupper($skill) === $leadProduct || str_contains($leadProduct, strtoupper($skill))) {
                    return 1.0;
                }
            }
        }

        // Category fallback
        if (! empty($agent->category_skills)) {
            // Category matching requires external Product Catalog lookup;
            // for now we return 0.5 as a soft match when category skills exist
            return 0.5;
        }

        return 0.0;
    }

    /**
     * Compute region match score (0–1) between agent and lead.
     */
    private function computeRegionMatch(AgentProfile $agent, Lead $lead): float
    {
        if (empty($agent->regions)) {
            return 0.0;
        }

        $leadRegions = array_filter([$lead->state, $lead->city, $lead->barangay]);
        if (empty($leadRegions)) {
            return 0.5; // Neutral when no region data
        }

        foreach ($agent->regions as $region) {
            $regionUpper = strtoupper($region);
            foreach ($leadRegions as $leadRegion) {
                if ($leadRegion && strtoupper($leadRegion) === $regionUpper) {
                    return 1.0;
                }
            }
        }

        return 0.0;
    }
}
