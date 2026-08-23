<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\ConversationAssignmentHistory;
use App\Domain\Shop\Models\PageAssignmentRule;
use App\Models\AgentProfile;
use App\Models\Customer;
use App\Models\SiteSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Routes incoming conversations to eligible agents.
 *
 * Strategies: round_robin | skill_based | workload | hybrid
 * Configured via SiteSetting keys prefixed "auto_assign_".
 */
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

    /** Hard ceiling for bulk operations per the Phase 2 spec. */
    public const MAX_BULK = 100;

    /** Hybrid weighting: skills dominate, workload matters, recency breaks ties. */
    private const HYBRID_WEIGHTS = [
        self::STRATEGY_SKILL_BASED => 0.45,
        self::STRATEGY_WORKLOAD => 0.35,
        self::STRATEGY_ROUND_ROBIN => 0.20,
    ];

    /**
     * @return list<string>
     */
    public static function strategies(): array
    {
        return [
            self::STRATEGY_ROUND_ROBIN,
            self::STRATEGY_SKILL_BASED,
            self::STRATEGY_WORKLOAD,
            self::STRATEGY_HYBRID,
        ];
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public function isEnabled(): bool
    {
        return filter_var($this->setting('enabled', true), FILTER_VALIDATE_BOOL);
    }

    public function getStrategy(): string
    {
        $strategy = (string) $this->setting('strategy', self::STRATEGY_HYBRID);

        return array_key_exists($strategy, self::STRATEGIES) ? $strategy : self::STRATEGY_HYBRID;
    }

    public function queueLimit(AgentProfile $profile): int
    {
        return max(1, $profile->concurrent_lead_cap ?? (int) $this->setting('queue_limit', 15));
    }

    public function dailyLimit(AgentProfile $profile): int
    {
        return max(1, $profile->max_daily_leads ?: (int) $this->setting('daily_limit', 50));
    }

    /** @return string[] */
    public function assignableRoles(): array
    {
        $raw = $this->setting('roles');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        $roles = array_values(array_filter((array) $decoded, is_string(...)));

        return $roles === [] ? ['agent', 'teamleader'] : $roles;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return [
            'strategy' => $this->getStrategy(),
            'enabled' => $this->isEnabled(),
            'fallback_agent_id' => $this->setting('fallback_agent_id'),
            'respect_shift_hours' => filter_var($this->setting('respect_shift', true), FILTER_VALIDATE_BOOL),
            'respect_queue_limits' => filter_var($this->setting('respect_queue_limits', true), FILTER_VALIDATE_BOOL),
            'strategies' => self::STRATEGIES,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(array $settings): void
    {
        $map = [
            'strategy' => 'strategy',
            'enabled' => 'enabled',
            'fallback_agent_id' => 'fallback_agent_id',
            'respect_shift_hours' => 'respect_shift',
            'respect_queue_limits' => 'respect_queue_limits',
        ];

        foreach ($map as $key => $settingKey) {
            if (array_key_exists($key, $settings)) {
                SiteSetting::set('auto_assign_'.$settingKey, $settings[$key]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Assignment entry points
    // -------------------------------------------------------------------------

    /**
     * Route one conversation. Order of precedence:
     * page rule (while its agent is eligible) → configured fallback agent
     * (only when nobody else qualifies) → strategy pick.
     */
    public function assign(Conversation $conversation): ?int
    {
        if (! $this->isEnabled()) {
            return $conversation->assigned_agent_id;
        }

        if ($conversation->assigned_agent_id !== null) {
            return $conversation->assigned_agent_id;
        }

        $candidates = $this->eligibleAgents();
        $rule = PageAssignmentRule::query()
            ->where('facebook_page_id', $conversation->facebook_page_id)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        $chosen = null;
        $reason = null;
        $strategy = $this->getStrategy();

        if ($rule !== null && $candidates->has($rule->user_id)) {
            $chosen = $candidates[$rule->user_id];
            $reason = 'page_rule';
        }

        if ($chosen === null && $candidates->isNotEmpty()) {
            $chosen = $this->pickByStrategy($candidates, $conversation, $strategy);
            $reason = 'auto_'.$strategy;
        }

        if ($chosen === null) {
            $fallbackUser = $this->fallbackAgent();

            if ($fallbackUser !== null) {
                $this->applyAssignment($conversation, $fallbackUser, 'fallback', $strategy);

                return $fallbackUser->id;
            }

            return null;
        }

        $this->applyAssignment($conversation, $chosen['user'], (string) $reason, $strategy);

        return $chosen['user']->id;
    }

    /**
     * Bulk-assign unassigned NEW conversations (bounded by MAX_BULK).
     *
     * @param  list<int>  $conversationIds
     * @return int number routed successfully
     */
    public function bulkAssign(array $conversationIds): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $assigned = 0;

        foreach (array_slice(array_unique(array_map(intval(...), $conversationIds)), 0, self::MAX_BULK) as $id) {
            $conversation = Conversation::query()
                ->whereNull('assigned_agent_id')
                ->where('status', Conversation::STATUS_NEW)
                ->find($id);

            if ($conversation !== null && $this->assign($conversation) !== null) {
                $assigned++;
            }
        }

        return $assigned;
    }

    /**
     * Sweep unassigned NEW conversations for the dashboard button.
     *
     * @return array{assigned: int, skipped: int, errors: list<string>, total: int}
     */
    public function bulkAutoAssign(): array
    {
        $result = ['assigned' => 0, 'skipped' => 0, 'errors' => [], 'total' => 0];

        if (! $this->isEnabled()) {
            return $result;
        }

        $conversations = Conversation::query()
            ->whereNull('assigned_agent_id')
            ->where('status', Conversation::STATUS_NEW)
            ->whereNull('merged_into_id')
            ->limit(self::MAX_BULK)
            ->get();

        $result['total'] = $conversations->count();

        foreach ($conversations as $conversation) {
            try {
                if ($this->assign($conversation) !== null) {
                    $result['assigned']++;
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['skipped']++;
                $result['errors'][] = "Conversation #{$conversation->id}: {$e->getMessage()}";
            }
        }

        return $result;
    }

    /** Manual override path: move a conversation to a specific agent (or none). */
    public function reassign(Conversation $conversation, ?int $agentId, string $reason, ?int $actorId = null): bool
    {
        $previousAgentId = $conversation->assigned_agent_id;

        $conversation->assigned_agent_id = $agentId;

        $saved = $agentId !== null && $conversation->canTransitionTo(Conversation::STATUS_ASSIGNED)
            ? tap($conversation)->update(['status' => Conversation::STATUS_ASSIGNED])
            : $conversation->save();

        ConversationAssignmentHistory::query()->create([
            'conversation_id' => $conversation->id,
            'from_agent_id' => $previousAgentId,
            'to_agent_id' => $agentId,
            'assigned_by_id' => $actorId,
            'reason' => $reason.($previousAgentId !== null ? " (from agent #{$previousAgentId})" : ''),
        ]);

        if ($agentId !== null) {
            $this->touchLastAssignment($agentId);
        }

        return (bool) $saved;
    }

    // -------------------------------------------------------------------------
    // Eligibility
    // -------------------------------------------------------------------------

    /**
     * Agents that may receive conversations right now, keyed by user id.
     * Applies: active account, assignable role, opt-in + availability flags,
     * shift hours, concurrent queue cap and daily cap.
     *
     * @return Collection<int, array{user: User, profile: AgentProfile, active_count: int}>
     */
    public function eligibleAgents(?CarbonInterface $at = null): Collection
    {
        $users = User::query()
            ->whereIn('role', $this->assignableRoles())
            ->where('is_active', true)
            ->with('agentProfile')
            ->get();

        [$activeCounts, $todayCounts] = $this->loadCounts();

        $candidates = collect();

        foreach ($users as $user) {
            $profile = $user->agentProfile;

            if ($profile === null
                || ! $profile->auto_assign_enabled
                || ! $profile->is_available
                || ! $this->isWithinShift($profile, $at)) {
                continue;
            }

            $activeCount = (int) ($activeCounts[$user->id] ?? 0);
            $todayCount = (int) ($todayCounts[$user->id] ?? 0);

            if ($activeCount >= $this->queueLimit($profile) || $todayCount >= $this->dailyLimit($profile)) {
                continue;
            }

            $candidates->put($user->id, [
                'user' => $user,
                'profile' => $profile,
                'active_count' => $activeCount,
            ]);
        }

        return $candidates;
    }

    /**
     * One grouped query per counter instead of per-agent lookups.
     *
     * @return array{0: Collection<string, int>, 1: Collection<string, int>} [active load, assigned-today]
     */
    private function loadCounts(): array
    {
        $base = Conversation::query()->selectRaw('assigned_agent_id, COUNT(*) as total');

        $active = (clone $base)
            ->whereIn('status', Conversation::ACTIVE_STATUSES)
            ->whereNull('merged_into_id')
            ->groupBy('assigned_agent_id')
            ->pluck('total', 'assigned_agent_id');

        $today = ConversationAssignmentHistory::query()
            ->whereDate('created_at', today())
            ->groupBy('to_agent_id')
            ->selectRaw('to_agent_id, COUNT(*) as total')
            ->pluck('total', 'to_agent_id');

        return [$active, $today];
    }

    /**
     * Shift window check; supports overnight windows (e.g. 22:00 → 06:00).
     * Agents without a configured shift are always in. When every configured
     * agent is off-shift the caller decides whether to relax the filter —
     * here we keep it strict so off-hours traffic waits for the next shift.
     */
    public function isWithinShift(AgentProfile $profile, ?CarbonInterface $at = null): bool
    {
        if ($profile->shift_start === null || $profile->shift_end === null) {
            return true;
        }

        $now = ($at ?? now())->format('H:i:s');
        $start = (string) $profile->shift_start;
        $end = (string) $profile->shift_end;

        return $start <= $end
            ? $now >= $start && $now < $end
            : ($now >= $start || $now < $end);
    }

    // -------------------------------------------------------------------------
    // Strategy selection
    // -------------------------------------------------------------------------

    /**
     * @param  Collection<int, array{user: User, profile: AgentProfile, active_count: int}>  $candidates
     * @return array{user: User, profile: AgentProfile, active_count: int}|null
     */
    public function pickByStrategy(Collection $candidates, Conversation $conversation, string $strategy): ?array
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        return match ($strategy) {
            self::STRATEGY_SKILL_BASED => $this->pickBySkillScore($candidates, $conversation),
            self::STRATEGY_WORKLOAD => $this->pickByWorkload($candidates),
            self::STRATEGY_HYBRID => $this->pickByHybrid($candidates, $conversation),
            default => $this->pickByRoundRobin($candidates),
        };
    }

    /**
     * Least-recently-assigned first; never-assigned agents lead.
     *
     * @param  Collection<int, array{user: User, profile: AgentProfile, active_count: int}>  $candidates
     * @return array{user: User, profile: AgentProfile, active_count: int}|null
     */
    public function pickByRoundRobin(Collection $candidates): ?array
    {
        return $candidates->sortBy(fn (array $candidate) => $candidate['profile']->last_assignment_at?->getTimestamp() ?? 0)
            ->first();
    }

    /**
     * Fewest active conversations; least-recently-assigned breaks ties.
     *
     * @param  Collection<int, array{user: User, profile: AgentProfile, active_count: int}>  $candidates
     * @return array{user: User, profile: AgentProfile, active_count: int}|null
     */
    public function pickByWorkload(Collection $candidates): ?array
    {
        return $candidates->sort(fn (array $a, array $b) => [$a['active_count'], $a['profile']->last_assignment_at?->getTimestamp() ?? 0]
            <=> [$b['active_count'], $b['profile']->last_assignment_at?->getTimestamp() ?? 0])
            ->first();
    }

    /**
     * Highest skill match against the conversation context; performance score breaks ties.
     *
     * @param  Collection<int, array{user: User, profile: AgentProfile, active_count: int}>  $candidates
     * @return array{user: User, profile: AgentProfile, active_count: int}|null
     */
    public function pickBySkillScore(Collection $candidates, Conversation $conversation): ?array
    {
        $context = $this->contextFor($conversation);

        return $candidates->sortByDesc(fn (array $candidate) => [
            $this->skillScore($candidate['profile'], $context),
            $candidate['profile']->performance_score,
        ])->first();
    }

    /**
     * Weighted blend: 45% skill fit, 35% free queue capacity, 20% assignment recency.
     *
     * @param  Collection<int, array{user: User, profile: AgentProfile, active_count: int}>  $candidates
     * @return array{user: User, profile: AgentProfile, active_count: int}|null
     */
    public function pickByHybrid(Collection $candidates, Conversation $conversation): ?array
    {
        $context = $this->contextFor($conversation);

        return $candidates->sortByDesc(function (array $candidate) use ($context) {
            $skill = $this->skillScore($candidate['profile'], $context);
            $workload = 1 - min(1, $candidate['active_count'] / $this->queueLimit($candidate['profile']));
            $recency = $this->recencyScore($candidate['profile']);

            return self::HYBRID_WEIGHTS[self::STRATEGY_SKILL_BASED] * $skill
                + self::HYBRID_WEIGHTS[self::STRATEGY_WORKLOAD] * $workload
                + self::HYBRID_WEIGHTS[self::STRATEGY_ROUND_ROBIN] * $recency;
        })->first();
    }

    /**
     * Score 0..1 across product/region/category dimensions present in context.
     * An agent without skills configured scores a neutral 0.5 per dimension.
     *
     * @param  array{product: ?string, region: ?string, category: ?string}  $context
     */
    public function skillScore(AgentProfile $profile, array $context): float
    {
        $dimensions = [
            ['context' => $context['product'], 'skills' => $profile->product_skills],
            ['context' => $context['region'], 'skills' => $profile->regions],
            ['context' => $context['category'], 'skills' => $profile->category_skills],
        ];

        $applicable = array_values(array_filter(
            $dimensions,
            fn (array $dimension) => $dimension['context'] !== null && $dimension['context'] !== '',
        ));

        if ($applicable === []) {
            return 0.5;
        }

        $total = 0.0;

        foreach ($applicable as ['context' => $value, 'skills' => $skills]) {
            $list = is_array($skills) ? $skills : [];

            $total += match (true) {
                $list === [] => 0.5,
                $this->matchesAnySkill((string) $value, $list) => 1.0,
                default => 0.0,
            };
        }

        return $total / count($applicable);
    }

    /**
     * Case-insensitive containment either way, so "fashion" matches the
     * context value "Fashion & Apparel" and vice versa.
     *
     * @param  list<mixed>  $skills
     */
    private function matchesAnySkill(string $value, array $skills): bool
    {
        $valueUpper = mb_strtoupper(trim($value));

        foreach ($skills as $skill) {
            if (! is_string($skill) || trim($skill) === '') {
                continue;
            }

            $skillUpper = mb_strtoupper(trim($skill));

            if (str_contains($valueUpper, $skillUpper) || str_contains($skillUpper, $valueUpper)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 0 → just assigned; 1 → idle all day (or never assigned).
     */
    public function recencyScore(AgentProfile $profile): float
    {
        if ($profile->last_assignment_at === null) {
            return 1.0;
        }

        $hoursIdle = (float) $profile->last_assignment_at->diffInHours(now());

        return min(1.0, $hoursIdle / 24);
    }

    /**
     * Assignment context hints: customer region plus any metadata markers.
     *
     * @return array{product: ?string, region: ?string, category: ?string}
     */
    public function contextFor(Conversation $conversation): array
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        $customer = $conversation->customer;
        $region = $metadata['region']
            ?? ($customer instanceof Customer
                ? ($customer->region ?? $customer->province ?? $customer->city_municipality ?? null)
                : null);

        return [
            'product' => $metadata['product'] ?? null,
            'region' => is_string($region) ? $region : null,
            'category' => $metadata['category']
                ?? ($conversation->facebookPage?->category ?? null),
        ];
    }

    /** Configured last-resort agent; must still be an active account. */
    private function fallbackAgent(): ?User
    {
        $fallbackId = $this->setting('fallback_agent_id');

        if (! is_numeric($fallbackId)) {
            return null;
        }

        $user = User::query()->find((int) $fallbackId);

        return $user !== null && $user->is_active ? $user : null;
    }

    private function historyQueryToday(): Builder
    {
        return ConversationAssignmentHistory::query()->whereDate('created_at', today());
    }

    /**
     * Today's assignment counters for the stats endpoint.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'today_auto' => $this->historyQueryToday()->where('reason', 'like', 'auto_%')->count(),
            'today_page_rule' => $this->historyQueryToday()->where('reason', 'page_rule')->count(),
            'today_manual' => $this->historyQueryToday()->where('reason', 'manual')->count(),
            'today_fallback' => $this->historyQueryToday()->where('reason', 'fallback')->count(),
            'unassigned_count' => Conversation::query()
                ->whereNull('assigned_agent_id')
                ->where('status', Conversation::STATUS_NEW)
                ->whereNull('merged_into_id')
                ->count(),
            'eligible_agents' => $this->eligibleAgents()->count(),
            'by_strategy' => $this->historyQueryToday()
                ->where('reason', 'like', 'auto_%')
                ->selectRaw('reason, COUNT(*) as count')
                ->groupBy('reason')
                ->pluck('count', 'reason')
                ->toArray(),
            'current_strategy' => $this->getStrategy(),
        ];
    }

    // -------------------------------------------------------------------------
    // Persistence helpers
    // -------------------------------------------------------------------------

    private function applyAssignment(Conversation $conversation, User $agent, string $reason, string $strategy): void
    {
        DB::transaction(function () use ($conversation, $agent, $reason, $strategy) {
            $fromAgentId = $conversation->getOriginal('assigned_agent_id');

            $conversation->assigned_agent_id = $agent->id;

            if ($conversation->canTransitionTo(Conversation::STATUS_ASSIGNED)) {
                $conversation->status = Conversation::STATUS_ASSIGNED;
            }

            $conversation->save();

            $this->touchLastAssignment($agent->id);

            ConversationAssignmentHistory::query()->create([
                'conversation_id' => $conversation->id,
                'from_agent_id' => $fromAgentId,
                'to_agent_id' => $agent->id,
                'assigned_by_id' => null,
                'reason' => $reason === 'page_rule' || str_starts_with($reason, 'auto_') ? $reason : 'auto_'.$strategy,
            ]);
        });
    }

    private function touchLastAssignment(int $agentId): void
    {
        AgentProfile::query()
            ->where('user_id', $agentId)
            ->update(['last_assignment_at' => now()]);
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return SiteSetting::get('auto_assign_'.$key, $default);
    }
}
