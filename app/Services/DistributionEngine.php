<?php

namespace App\Services;

use Modules\Leads\Enums\DistributionStrategy;
use Modules\Leads\Models\Lead;
use App\Models\AgentProfile;
use App\Models\AgentWorkload;
use App\Models\DistributionRule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DistributionEngine
{
    public function __construct(
        private CapacityManager $capacityManager,
        private AgentAvailability $agentAvailability,
        private LeadPoolService $poolService,
        private LeadAuditService $auditService,
        private RuleConditionEvaluator $conditionEvaluator,
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
            // C2: Skip this rule if the lead doesn't match the rule's lead-side conditions
            if (! $this->conditionEvaluator->matches($lead, $rule)) {
                continue;
            }

            $eligible = $this->filterEligibleAgents($lead, $rule);
            if ($eligible->isEmpty()) {
                continue;
            }

            $scored = $this->scoreAgents($lead, $eligible, $rule);

            // For ROUND_ROBIN strategy use a per-rule RR cursor (BUG-17)
            if ($rule->strategy === DistributionStrategy::ROUND_ROBIN) {
                $rrKey = config('app.env').':distribution:rr:rule:'.$rule->id;
                $agentIds = $eligible->pluck('user_id')->sort()->values()->all();
                $lastKey = Cache::get($rrKey, -1);
                $nextKey = ($lastKey + 1) % count($agentIds);
                Cache::put($rrKey, $nextKey, now()->addDay());
                $bestId = $agentIds[$nextKey];
            } else {
                $best = $scored->first();
                $bestId = $best ? $best['agent_id'] : null;
            }

            if ($bestId) {
                return [
                    'agent_id' => $bestId,
                    'rule_id' => $rule->id,
                    'score' => $scored->firstWhere('agent_id', $bestId)['score'] ?? 0.0,
                    'reason' => "Matched via rule '{$rule->name}' ({$rule->strategy->value})",
                ];
            }
        }

        // Fallback: round-robin across all capacity-eligible agents
        $eligible = $this->filterEligibleAgents($lead, null);
        if ($eligible->isNotEmpty()) {
            // Key is namespaced by environment + 'fallback' segment (BUG-17: per-rule scoping handled above via rule loop)
            $rrKey = config('app.env').':distribution:rr:fallback';
            $agentIds = $eligible->pluck('user_id')->sort()->values()->all();
            $lastKey = Cache::get($rrKey, -1);
            $nextKey = ($lastKey + 1) % count($agentIds);
            $bestId = $agentIds[$nextKey];
            Cache::put($rrKey, $nextKey, now()->addDay());

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

            // Shift check — use already-loaded profile data to avoid N+1 (ISSUE-C)
            if (! $this->isWithinShiftFromProfile($profile)) {
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
     * @param  Collection<int, AgentProfile>  $agents
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

        // Pre-fetch all workloads in one query — no N+1 (ISS-007)
        $agentIds = $agents->pluck('user_id')->all();
        $workloads = AgentWorkload::whereIn('agent_id', $agentIds)
            ->get()
            ->keyBy('agent_id');

        $scored = $agents->map(function (AgentProfile $agent) use ($lead, $formula, $strategy, $workloads) {
            $score = 0.0;
            $workload = $workloads->get($agent->user_id)
                ?? new AgentWorkload(['agent_id' => $agent->user_id, 'active_leads_count' => 0, 'today_assigned_count' => 0]);

            // Performance score (normalized 0–100)
            $wPerf = $formula['w_perf'] ?? 0.30;
            $perfNormalized = ($agent->performance_score ?? 50) / 100;
            $score += $wPerf * $perfNormalized;

            // Availability factor — computed from already-loaded profile data (BUG-10: no N+1)
            $wAvail = $formula['w_avail'] ?? 0.25;
            $availFactor = $this->isWithinShiftFromProfile($agent) ? 1.0 : 0.0;
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
            } elseif ($strategy === DistributionStrategy::PREDICTIVE) {
                // Use the predictive service for scoring
                $predictiveResult = app(PredictiveAssignmentService::class)
                    ->predict($lead, collect([$agent]));
                $score = $score * 0.2 + ($predictiveResult['score'] * 0.8);
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
     * Determine shift eligibility from an already-loaded AgentProfile (no extra DB query).
     */
    private function isWithinShiftFromProfile(AgentProfile $profile): bool
    {
        if (! $profile->shift_start || ! $profile->shift_end) {
            return true;
        }

        $nowTime = Carbon::now()->format('H:i');
        $startTime = Carbon::parse($profile->shift_start)->format('H:i');
        $endTime = Carbon::parse($profile->shift_end)->format('H:i');

        if ($endTime < $startTime) {
            return $nowTime >= $startTime || $nowTime < $endTime;
        }

        return $nowTime >= $startTime && $nowTime < $endTime;
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
