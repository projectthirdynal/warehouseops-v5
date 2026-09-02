<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use App\Models\AgentProfile;
use App\Models\SiteSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Shop\Models\Conversation;
use Modules\Shop\Models\ConversationAssignmentHistory;
use Modules\Shop\Models\PageAssignmentRule;

class AutoAssignmentService
{
    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    public const STRATEGY_SKILL_BASED = 'skill_based';

    public const STRATEGY_WORKLOAD = 'workload';

    public const STRATEGY_HYBRID = 'hybrid';

    public const STRATEGIES = [
        self::STRATEGY_ROUND_ROBIN => 'Round-Robin',
        self::STRATEGY_SKILL_BASED => 'Skill-Based',
        self::STRATEGY_WORKLOAD => 'Workload-Based',
        self::STRATEGY_HYBRID => 'Hybrid (Skill + Workload + Round-Robin)',
    ];

    public function assign(Conversation $conversation): ?int
    {
        $rule = PageAssignmentRule::query()
            ->where('facebook_page_id', $conversation->facebook_page_id)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if ($rule !== null) {
            $this->applyAssignment($conversation, $rule->user_id, 'page_rule');

            return $rule->user_id;
        }

        $strategy = $this->getStrategy();
        $agents = $this->getEligibleAgents($conversation);

        if ($agents->isEmpty()) {
            return null;
        }

        $agentId = match ($strategy) {
            self::STRATEGY_ROUND_ROBIN => $this->selectRoundRobin($agents),
            self::STRATEGY_SKILL_BASED => $this->selectSkillBased($agents, $conversation),
            self::STRATEGY_WORKLOAD => $this->selectWorkload($agents),
            default => $this->selectHybrid($agents, $conversation),
        };

        if ($agentId !== null) {
            $this->applyAssignment($conversation, $agentId, 'auto_'.$strategy);
        }

        return $agentId;
    }

    public function bulkAutoAssign(): array
    {
        $conversations = Conversation::query()
            ->whereNull('assigned_agent_id')
            ->whereIn('status', ['open', 'new', 'pending'])
            ->whereNull('merged_into_id')
            ->limit(100)
            ->get();

        $assigned = 0;
        $skipped = 0;
        $errors = [];

        foreach ($conversations as $conversation) {
            try {
                $result = $this->assign($conversation);
                if ($result !== null) {
                    $assigned++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Conversation #{$conversation->id}: {$e->getMessage()}";
            }
        }

        return [
            'assigned' => $assigned,
            'skipped' => $skipped,
            'errors' => $errors,
            'total' => $conversations->count(),
        ];
    }

    public function getStrategy(): string
    {
        $strategy = SiteSetting::get('auto_assign_strategy', self::STRATEGY_HYBRID);

        return array_key_exists($strategy, self::STRATEGIES) ? $strategy : self::STRATEGY_HYBRID;
    }

    public function getSettings(): array
    {
        return [
            'strategy' => $this->getStrategy(),
            'enabled' => (bool) SiteSetting::get('auto_assign_enabled', true),
            'fallback_agent_id' => SiteSetting::get('auto_assign_fallback_agent_id'),
            'respect_shift_hours' => (bool) SiteSetting::get('auto_assign_respect_shift', true),
            'respect_queue_limits' => (bool) SiteSetting::get('auto_assign_respect_queue_limits', true),
            'strategies' => self::STRATEGIES,
        ];
    }

    public function updateSettings(array $settings): void
    {
        $map = [
            'strategy' => 'auto_assign_strategy',
            'enabled' => 'auto_assign_enabled',
            'fallback_agent_id' => 'auto_assign_fallback_agent_id',
            'respect_shift_hours' => 'auto_assign_respect_shift',
            'respect_queue_limits' => 'auto_assign_respect_queue_limits',
        ];

        foreach ($map as $key => $settingKey) {
            if (array_key_exists($key, $settings)) {
                SiteSetting::set($settingKey, $settings[$key]);
            }
        }
    }

    public function getStats(): array
    {
        $today = today();

        $autoAssigned = ConversationAssignmentHistory::query()
            ->whereDate('created_at', $today)
            ->where('reason', 'like', 'auto_%')
            ->count();

        $pageRuleAssigned = ConversationAssignmentHistory::query()
            ->whereDate('created_at', $today)
            ->where('reason', 'page_rule')
            ->count();

        $manualAssigned = ConversationAssignmentHistory::query()
            ->whereDate('created_at', $today)
            ->where('reason', 'manual')
            ->count();

        $unassigned = Conversation::query()
            ->whereNull('assigned_agent_id')
            ->whereIn('status', ['open', 'new', 'pending'])
            ->whereNull('merged_into_id')
            ->count();

        $eligibleAgents = AgentProfile::query()
            ->where('auto_assign_enabled', true)
            ->where('is_available', true)
            ->count();

        $byStrategy = ConversationAssignmentHistory::query()
            ->whereDate('created_at', $today)
            ->where('reason', 'like', 'auto_%')
            ->selectRaw('reason, COUNT(*) as count')
            ->groupBy('reason')
            ->pluck('count', 'reason')
            ->toArray();

        return [
            'today_auto' => $autoAssigned,
            'today_page_rule' => $pageRuleAssigned,
            'today_manual' => $manualAssigned,
            'unassigned_count' => $unassigned,
            'eligible_agents' => $eligibleAgents,
            'by_strategy' => $byStrategy,
            'current_strategy' => $this->getStrategy(),
        ];
    }

    // ─── Strategy implementations ───────────────────────────────────

    private function selectRoundRobin(Collection $agents): ?int
    {
        return $agents
            ->sortBy(fn ($a) => $a['last_assignment_at'] === null ? 0 : 1)
            ->sortBy('last_assignment_at')
            ->first()['id'] ?? null;
    }

    private function selectSkillBased(Collection $agents, Conversation $conversation): ?int
    {
        return $this->scoreSkills($agents, $conversation)
            ->sortByDesc('skill_score')
            ->first()['id'] ?? null;
    }

    private function selectWorkload(Collection $agents): ?int
    {
        return $agents->sortBy('active_count')->first()['id'] ?? null;
    }

    private function selectHybrid(Collection $agents, Conversation $conversation): ?int
    {
        return $this->scoreSkills($agents, $conversation)
            ->sortByDesc('skill_score')
            ->sortBy('active_count')
            ->sortBy(fn ($a) => $a['last_assignment_at'] === null ? 0 : 1)
            ->sortBy('last_assignment_at')
            ->first()['id'] ?? null;
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function getEligibleAgents(Conversation $conversation): Collection
    {
        $activeStatuses = Conversation::ACTIVE_STATUSES;

        $agents = User::query()
            ->where('users.is_active', true)
            ->whereIn('users.role', ['agent', 'supervisor'])
            ->join('agent_profiles', 'agent_profiles.user_id', '=', 'users.id')
            ->where('agent_profiles.auto_assign_enabled', true)
            ->where('agent_profiles.is_available', true)
            ->leftJoin('conversations', function ($join) use ($activeStatuses) {
                $join->on('conversations.assigned_agent_id', '=', 'users.id')
                    ->whereIn('conversations.status', $activeStatuses)
                    ->whereNull('conversations.merged_into_id');
            })
            ->groupBy(
                'users.id',
                'users.name',
                'agent_profiles.last_assignment_at',
                'agent_profiles.product_skills',
                'agent_profiles.regions',
                'agent_profiles.category_skills',
                'agent_profiles.max_active_conversations',
                'agent_profiles.overflow_enabled',
                'agent_profiles.shift_start',
                'agent_profiles.shift_end',
            )
            ->selectRaw(
                'users.id, users.name, agent_profiles.last_assignment_at, '
                .'agent_profiles.product_skills, agent_profiles.regions, agent_profiles.category_skills, '
                .'agent_profiles.max_active_conversations, agent_profiles.overflow_enabled, '
                .'agent_profiles.shift_start, agent_profiles.shift_end, '
                .'COUNT(conversations.id) as active_count'
            )
            ->get();

        if ($agents->isEmpty()) {
            return collect();
        }

        $respectShift = (bool) SiteSetting::get('auto_assign_respect_shift', true);
        $respectQueue = (bool) SiteSetting::get('auto_assign_respect_queue_limits', true);

        if ($respectShift) {
            $nowTime = now()->format('H:i');
            $inShift = $agents->filter(function ($agent) use ($nowTime) {
                $start = $agent->shift_start;
                $end = $agent->shift_end;
                if (! $start || ! $end) {
                    return true;
                }
                $startTime = Carbon::parse($start)->format('H:i');
                $endTime = Carbon::parse($end)->format('H:i');
                if ($endTime < $startTime) {
                    return $nowTime >= $startTime || $nowTime < $endTime;
                }

                return $nowTime >= $startTime && $nowTime < $endTime;
            });
            $agents = $inShift->isNotEmpty() ? $inShift : $agents;
        }

        if ($respectQueue) {
            $available = $agents->filter(function ($agent) {
                $atLimit = (int) $agent->active_count >= (int) ($agent->max_active_conversations ?? 15);
                if ($atLimit && ! (bool) ($agent->overflow_enabled ?? true)) {
                    return false;
                }

                return true;
            });
            $agents = $available->isNotEmpty() ? $available : $agents;
        }

        return $agents->map(function ($agent) {
            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'last_assignment_at' => $agent->last_assignment_at,
                'active_count' => (int) $agent->active_count,
                'product_skills' => $agent->product_skills ?? [],
                'regions' => $agent->regions ?? [],
                'category_skills' => $agent->category_skills ?? [],
                'max_active_conversations' => (int) ($agent->max_active_conversations ?? 15),
                'overflow_enabled' => (bool) ($agent->overflow_enabled ?? true),
            ];
        });
    }

    private function scoreSkills(Collection $agents, Conversation $conversation): Collection
    {
        $pageCategory = $conversation->facebookPage?->category;
        $customerRegion = $conversation->customer?->region
            ?? $conversation->customer?->province
            ?? $conversation->customer?->city_municipality
            ?? null;
        $messageBody = $conversation->last_message_preview ?? '';

        return $agents->map(function ($agent) use ($pageCategory, $customerRegion, $messageBody) {
            $score = 0;
            $categorySkills = $agent['category_skills'] ?? [];
            $regions = $agent['regions'] ?? [];
            $productSkills = $agent['product_skills'] ?? [];

            if ($pageCategory && ! empty($categorySkills)) {
                $pageCatUpper = strtoupper($pageCategory);
                foreach ($categorySkills as $skill) {
                    if (str_contains($pageCatUpper, strtoupper($skill))) {
                        $score += 3;
                        break;
                    }
                }
            }

            if ($customerRegion && ! empty($regions)) {
                $custRegionUpper = strtoupper($customerRegion);
                foreach ($regions as $region) {
                    if (str_contains($custRegionUpper, strtoupper($region))) {
                        $score += 2;
                        break;
                    }
                }
            }

            if ($messageBody !== '' && ! empty($productSkills)) {
                $bodyUpper = strtoupper($messageBody);
                foreach ($productSkills as $skill) {
                    if (str_contains($bodyUpper, strtoupper($skill))) {
                        $score += 2;
                        break;
                    }
                }
            }

            return array_merge($agent, ['skill_score' => $score]);
        });
    }

    private function applyAssignment(Conversation $conversation, int $agentId, string $reason): void
    {
        $conversation->forceFill([
            'assigned_agent_id' => $agentId,
            'status' => Conversation::STATUS_ASSIGNED,
        ])->save();

        ConversationAssignmentHistory::create([
            'conversation_id' => $conversation->id,
            'from_agent_id' => $conversation->wasRecentlyCreated ? null : $conversation->getOriginal('assigned_agent_id'),
            'to_agent_id' => $agentId,
            'assigned_by_id' => null,
            'reason' => $reason,
        ]);

        AgentProfile::query()
            ->where('user_id', $agentId)
            ->update(['last_assignment_at' => now()]);
    }
}
