<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;
use Modules\Shop\Models\AgentBadge;
use Modules\Shop\Models\AgentMilestone;
use Modules\Shop\Models\AgentStreak;
use Modules\Shop\Models\Badge;
use Modules\Shop\Models\Conversation;
use Modules\Shop\Models\Milestone;

class GamificationService
{
    private const DEFAULT_BADGES = [
        ['name' => 'First Response', 'slug' => 'first-response', 'description' => 'Responded to your first conversation', 'icon' => 'MessageSquare', 'color' => 'info', 'category' => 'conversations', 'criteria_type' => 'conversations_responded', 'criteria_value' => 1],
        ['name' => 'Conversation Starter', 'slug' => 'conversation-starter', 'description' => 'Handled 100 conversations', 'icon' => 'MessagesSquare', 'color' => 'primary', 'category' => 'conversations', 'criteria_type' => 'conversations_handled', 'criteria_value' => 100],
        ['name' => 'Conversation Master', 'slug' => 'conversation-master', 'description' => 'Handled 500 conversations', 'icon' => 'MessagesSquare', 'color' => 'success', 'category' => 'conversations', 'criteria_type' => 'conversations_handled', 'criteria_value' => 500],
        ['name' => 'Quick Responder', 'slug' => 'quick-responder', 'description' => 'Resolved 50 conversations', 'icon' => 'Zap', 'color' => 'warning', 'category' => 'resolution', 'criteria_type' => 'conversations_resolved', 'criteria_value' => 50],
        ['name' => 'Resolution Pro', 'slug' => 'resolution-pro', 'description' => 'Resolved 200 conversations', 'icon' => 'CheckCircle', 'color' => 'success', 'category' => 'resolution', 'criteria_type' => 'conversations_resolved', 'criteria_value' => 200],
        ['name' => 'First Sale', 'slug' => 'first-sale', 'description' => 'Created your first order', 'icon' => 'ShoppingBag', 'color' => 'info', 'category' => 'sales', 'criteria_type' => 'orders_created', 'criteria_value' => 1],
        ['name' => 'Sales Star', 'slug' => 'sales-star', 'description' => 'Created 50 orders', 'icon' => 'Star', 'color' => 'warning', 'category' => 'sales', 'criteria_type' => 'orders_created', 'criteria_value' => 50],
        ['name' => 'Sales Champion', 'slug' => 'sales-champion', 'description' => 'Created 200 orders', 'icon' => 'Trophy', 'color' => 'success', 'category' => 'sales', 'criteria_type' => 'orders_created', 'criteria_value' => 200],
        ['name' => '7-Day Streak', 'slug' => '7-day-streak', 'description' => 'Active 7 consecutive days', 'icon' => 'Flame', 'color' => 'destructive', 'category' => 'streaks', 'criteria_type' => 'streak_days', 'criteria_value' => 7],
        ['name' => '30-Day Streak', 'slug' => '30-day-streak', 'description' => 'Active 30 consecutive days', 'icon' => 'Flame', 'color' => 'destructive', 'category' => 'streaks', 'criteria_type' => 'streak_days', 'criteria_value' => 30],
        ['name' => 'Speed Demon', 'slug' => 'speed-demon', 'description' => 'Avg first response under 5 minutes', 'icon' => 'Gauge', 'color' => 'info', 'category' => 'performance', 'criteria_type' => 'avg_response_time', 'criteria_value' => 300],
        ['name' => 'Customer Favorite', 'slug' => 'customer-favorite', 'description' => 'Received 10 positive sentiment conversations', 'icon' => 'Heart', 'color' => 'destructive', 'category' => 'sentiment', 'criteria_type' => 'positive_sentiment', 'criteria_value' => 10],
    ];

    private const DEFAULT_MILESTONES = [
        ['name' => 'First 10 Conversations', 'slug' => 'first-10-conversations', 'description' => 'Handle 10 conversations', 'metric' => 'conversations_handled', 'target_value' => 10, 'period' => 'all_time'],
        ['name' => 'First 10 Orders', 'slug' => 'first-10-orders', 'description' => 'Create 10 orders', 'metric' => 'orders_created', 'target_value' => 10, 'period' => 'all_time'],
        ['name' => '50 Resolved', 'slug' => '50-resolved', 'description' => 'Resolve 50 conversations', 'metric' => 'conversations_resolved', 'target_value' => 50, 'period' => 'all_time'],
        ['name' => '₱50K Revenue', 'slug' => '50k-revenue', 'description' => 'Generate ₱50,000 in delivered order revenue', 'metric' => 'revenue_generated', 'target_value' => 50000, 'period' => 'all_time'],
        ['name' => '₱100K Revenue', 'slug' => '100k-revenue', 'description' => 'Generate ₱100,000 in delivered order revenue', 'metric' => 'revenue_generated', 'target_value' => 100000, 'period' => 'all_time'],
        ['name' => '100 Conversations Today', 'slug' => '100-conversations-today', 'description' => 'Handle 100 conversations in a single day', 'metric' => 'conversations_handled', 'target_value' => 100, 'period' => 'daily'],
        ['name' => '20 Orders Today', 'slug' => '20-orders-today', 'description' => 'Create 20 orders in a single day', 'metric' => 'orders_created', 'target_value' => 20, 'period' => 'daily'],
    ];

    public function seedDefaults(): void
    {
        foreach (self::DEFAULT_BADGES as $badge) {
            Badge::firstOrCreate(['slug' => $badge['slug']], $badge);
        }

        foreach (self::DEFAULT_MILESTONES as $milestone) {
            Milestone::firstOrCreate(['slug' => $milestone['slug']], $milestone);
        }
    }

    public function getStats(): array
    {
        $totalBadges = Badge::where('is_active', true)->count();
        $totalAwarded = AgentBadge::count();
        $totalStreaks = AgentStreak::count();
        $totalMilestones = Milestone::where('is_active', true)->count();
        $totalMilestoneCompletions = AgentMilestone::whereNotNull('completed_at')->count();

        $activeStreaks = AgentStreak::where('current_streak', '>', 0)->count();
        $longestActiveStreak = AgentStreak::max('current_streak') ?? 0;
        $longestEverStreak = AgentStreak::max('longest_streak') ?? 0;

        $topStreaks = AgentStreak::with('user:id,name,role')
            ->where('current_streak', '>', 0)
            ->orderByDesc('current_streak')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'user_id' => $s->user_id,
                'user_name' => $s->user?->name,
                'current_streak' => $s->current_streak,
                'longest_streak' => $s->longest_streak,
                'last_activity_date' => $s->last_activity_date?->toDateString(),
            ]);

        $badgeDistribution = Badge::where('is_active', true)
            ->leftJoin('agent_badges', 'badges.id', '=', 'agent_badges.badge_id')
            ->select('badges.category', DB::raw('count(distinct agent_badges.id) as awarded_count'))
            ->groupBy('badges.category')
            ->pluck('awarded_count', 'badges.category')
            ->toArray();

        $recentAwards = AgentBadge::with(['badge:id,name,icon,color,category', 'user:id,name,role'])
            ->orderByDesc('awarded_at')
            ->limit(10)
            ->get()
            ->map(fn ($ab) => [
                'id' => $ab->id,
                'badge_name' => $ab->badge?->name,
                'badge_icon' => $ab->badge?->icon,
                'badge_color' => $ab->badge?->color,
                'user_name' => $ab->user?->name,
                'awarded_at' => $ab->awarded_at?->toIso8601String(),
            ]);

        return [
            'total_badges' => $totalBadges,
            'total_awarded' => $totalAwarded,
            'total_streaks' => $totalStreaks,
            'active_streaks' => $activeStreaks,
            'longest_active_streak' => $longestActiveStreak,
            'longest_ever_streak' => $longestEverStreak,
            'total_milestones' => $totalMilestones,
            'total_milestone_completions' => $totalMilestoneCompletions,
            'badge_distribution' => $badgeDistribution,
            'top_streaks' => $topStreaks,
            'recent_awards' => $recentAwards,
        ];
    }

    public function getLeaderboard(int $limit = 10): array
    {
        $agents = User::where('role', 'agent')
            ->where('is_active', true)
            ->with(['streaks' => fn ($q) => $q->where('streak_type', 'daily_activity')])
            ->withCount([
                'badges as badge_count',
                'milestones as completed_milestones' => fn ($q) => $q->whereNotNull('completed_at'),
            ])
            ->limit($limit)
            ->get()
            ->map(fn ($user) => [
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'badge_count' => $user->badge_count,
                'completed_milestones' => $user->completed_milestones,
                'current_streak' => $user->streaks->first()?->current_streak ?? 0,
            ])
            ->sortByDesc(fn ($a) => $a['badge_count'] + $a['completed_milestones'])
            ->take($limit)
            ->values();

        return $agents->toArray();
    }

    public function getAgentProfile(int $userId): array
    {
        $user = User::find($userId);
        if (! $user) {
            return ['error' => 'User not found'];
        }

        $badges = AgentBadge::with('badge')
            ->where('user_id', $userId)
            ->orderByDesc('awarded_at')
            ->get()
            ->map(fn ($ab) => [
                'id' => $ab->badge->id,
                'name' => $ab->badge->name,
                'description' => $ab->badge->description,
                'icon' => $ab->badge->icon,
                'color' => $ab->badge->color,
                'category' => $ab->badge->category,
                'awarded_at' => $ab->awarded_at?->toIso8601String(),
            ]);

        $streak = AgentStreak::where('user_id', $userId)
            ->where('streak_type', 'daily_activity')
            ->first();

        $milestones = AgentMilestone::with('milestone')
            ->where('user_id', $userId)
            ->get()
            ->map(fn ($am) => [
                'id' => $am->milestone->id,
                'name' => $am->milestone->name,
                'description' => $am->milestone->description,
                'metric' => $am->milestone->metric,
                'target_value' => $am->milestone->target_value,
                'current_value' => $am->current_value,
                'progress_pct' => $am->milestone->target_value > 0
                    ? min(100, round(($am->current_value / $am->milestone->target_value) * 100, 1))
                    : 0,
                'completed' => $am->completed_at !== null,
                'completed_at' => $am->completed_at?->toIso8601String(),
            ]);

        $allBadges = Badge::where('is_active', true)->orderBy('sort_order')->get();
        $earnedBadgeIds = $badges->pluck('id')->toArray();
        $availableBadges = $allBadges->map(fn ($b) => [
            'id' => $b->id,
            'name' => $b->name,
            'description' => $b->description,
            'icon' => $b->icon,
            'color' => $b->color,
            'category' => $b->category,
            'earned' => in_array($b->id, $earnedBadgeIds),
        ]);

        return [
            'user_id' => $userId,
            'user_name' => $user->name,
            'user_role' => $user->role,
            'badges' => $badges,
            'available_badges' => $availableBadges,
            'streak' => $streak ? [
                'current' => $streak->current_streak,
                'longest' => $streak->longest_streak,
                'last_activity_date' => $streak->last_activity_date?->toDateString(),
                'streak_started_at' => $streak->streak_started_at?->toDateString(),
            ] : null,
            'milestones' => $milestones,
            'total_badges' => $badges->count(),
            'total_milestones_completed' => $milestones->where('completed', true)->count(),
        ];
    }

    public function trackStreak(int $userId, ?string $streakType = 'daily_activity'): array
    {
        $today = Carbon::today();
        $streak = AgentStreak::firstOrCreate(
            ['user_id' => $userId, 'streak_type' => $streakType],
            ['current_streak' => 0, 'longest_streak' => 0]
        );

        if ($streak->last_activity_date && $streak->last_activity_date->eq($today)) {
            return [
                'current_streak' => $streak->current_streak,
                'longest_streak' => $streak->longest_streak,
                'message' => 'Already tracked today',
            ];
        }

        if ($streak->last_activity_date && $streak->last_activity_date->eq($today->copy()->subDay())) {
            $streak->current_streak += 1;
        } else {
            $streak->current_streak = 1;
            $streak->streak_started_at = $today;
        }

        $streak->last_activity_date = $today;

        if ($streak->current_streak > $streak->longest_streak) {
            $streak->longest_streak = $streak->current_streak;
        }

        $streak->save();

        $this->checkStreakBadges($userId, $streak->current_streak);

        return [
            'current_streak' => $streak->current_streak,
            'longest_streak' => $streak->longest_streak,
            'message' => 'Streak updated',
        ];
    }

    public function checkAndAwardBadges(int $userId): array
    {
        $awarded = [];
        $metrics = $this->computeAgentMetrics($userId);
        $badges = Badge::where('is_active', true)->get();

        foreach ($badges as $badge) {
            if (! $badge->criteria_type || ! $badge->criteria_value) {
                continue;
            }

            $exists = AgentBadge::where('user_id', $userId)->where('badge_id', $badge->id)->exists();
            if ($exists) {
                continue;
            }

            $metricValue = $metrics[$badge->criteria_type] ?? 0;

            if ($metricValue >= $badge->criteria_value) {
                AgentBadge::create([
                    'user_id' => $userId,
                    'badge_id' => $badge->id,
                    'awarded_at' => now(),
                ]);
                $awarded[] = [
                    'badge_id' => $badge->id,
                    'badge_name' => $badge->name,
                    'badge_icon' => $badge->icon,
                    'badge_color' => $badge->color,
                ];
            }
        }

        return $awarded;
    }

    public function updateMilestones(int $userId): array
    {
        $completed = [];
        $metrics = $this->computeAgentMetrics($userId);
        $milestones = Milestone::where('is_active', true)->get();

        foreach ($milestones as $milestone) {
            $agentMilestone = AgentMilestone::firstOrCreate(
                ['user_id' => $userId, 'milestone_id' => $milestone->id],
                ['current_value' => 0, 'completed_at' => null]
            );

            if ($agentMilestone->completed_at) {
                continue;
            }

            $metricValue = $metrics[$milestone->metric] ?? 0;
            $agentMilestone->current_value = $metricValue;

            if ($metricValue >= $milestone->target_value) {
                $agentMilestone->completed_at = now();

                if ($milestone->reward_badge_id) {
                    $existing = AgentBadge::where('user_id', $userId)
                        ->where('badge_id', $milestone->reward_badge_id)
                        ->exists();

                    if (! $existing) {
                        AgentBadge::create([
                            'user_id' => $userId,
                            'badge_id' => $milestone->reward_badge_id,
                            'awarded_at' => now(),
                        ]);
                    }
                }

                $completed[] = [
                    'milestone_id' => $milestone->id,
                    'milestone_name' => $milestone->name,
                    'target_value' => $milestone->target_value,
                    'actual_value' => $metricValue,
                ];
            }

            $agentMilestone->save();
        }

        return $completed;
    }

    public function bulkCheckAndAward(): array
    {
        $agents = User::where('role', 'agent')->where('is_active', true)->pluck('id');
        $totalBadgesAwarded = 0;
        $totalMilestonesCompleted = 0;

        foreach ($agents as $userId) {
            $badges = $this->checkAndAwardBadges($userId);
            $milestones = $this->updateMilestones($userId);
            $totalBadgesAwarded += count($badges);
            $totalMilestonesCompleted += count($milestones);
        }

        return [
            'agents_checked' => $agents->count(),
            'badges_awarded' => $totalBadgesAwarded,
            'milestones_completed' => $totalMilestonesCompleted,
            'message' => "Checked {$agents->count()} agents: {$totalBadgesAwarded} badges awarded, {$totalMilestonesCompleted} milestones completed",
        ];
    }

    private function computeAgentMetrics(int $userId): array
    {
        $conversationsHandled = Conversation::where('assigned_agent_id', $userId)->count();
        $conversationsResolved = Conversation::where('assigned_agent_id', $userId)->where('status', 'resolved')->count();
        $conversationsResponded = Conversation::where('assigned_agent_id', $userId)->whereNotNull('first_response_time_seconds')->count();

        $ordersCreated = Order::where('assigned_agent_id', $userId)->count();
        $revenueGenerated = (float) Order::where('assigned_agent_id', $userId)
            ->where('status', 'delivered')
            ->sum('total_amount');

        $streakDays = AgentStreak::where('user_id', $userId)
            ->where('streak_type', 'daily_activity')
            ->value('current_streak') ?? 0;

        $avgResponseTime = (int) Conversation::where('assigned_agent_id', $userId)
            ->whereNotNull('first_response_time_seconds')
            ->avg('first_response_time_seconds');

        $positiveSentiment = Conversation::where('assigned_agent_id', $userId)
            ->where('sentiment', 'positive')
            ->count();

        return [
            'conversations_handled' => $conversationsHandled,
            'conversations_resolved' => $conversationsResolved,
            'conversations_responded' => $conversationsResponded,
            'orders_created' => $ordersCreated,
            'revenue_generated' => $revenueGenerated,
            'streak_days' => $streakDays,
            'avg_response_time' => $avgResponseTime,
            'positive_sentiment' => $positiveSentiment,
        ];
    }

    private function checkStreakBadges(int $userId, int $currentStreak): void
    {
        $streakBadges = Badge::where('criteria_type', 'streak_days')
            ->where('criteria_value', '<=', $currentStreak)
            ->where('is_active', true)
            ->get();

        foreach ($streakBadges as $badge) {
            $exists = AgentBadge::where('user_id', $userId)->where('badge_id', $badge->id)->exists();
            if (! $exists) {
                AgentBadge::create([
                    'user_id' => $userId,
                    'badge_id' => $badge->id,
                    'awarded_at' => now(),
                ]);
            }
        }
    }

    public function getSettings(): array
    {
        return [
            'gamification_enabled' => SiteSetting::where('key', 'gamification_enabled')->value('value') ?? 'true',
            'auto_award_badges' => SiteSetting::where('key', 'gamification_auto_award_badges')->value('value') ?? 'true',
            'auto_track_streaks' => SiteSetting::where('key', 'gamification_auto_track_streaks')->value('value') ?? 'true',
            'streak_grace_period_hours' => (int) (SiteSetting::where('key', 'gamification_streak_grace_hours')->value('value') ?? 24),
            'leaderboard_size' => (int) (SiteSetting::where('key', 'gamification_leaderboard_size')->value('value') ?? 10),
        ];
    }

    public function updateSettings(array $settings): array
    {
        $map = [
            'gamification_enabled' => 'gamification_enabled',
            'auto_award_badges' => 'gamification_auto_award_badges',
            'auto_track_streaks' => 'gamification_auto_track_streaks',
            'streak_grace_period_hours' => 'gamification_streak_grace_hours',
            'leaderboard_size' => 'gamification_leaderboard_size',
        ];

        foreach ($settings as $key => $value) {
            if (isset($map[$key])) {
                SiteSetting::updateOrCreate(
                    ['key' => $map[$key]],
                    ['value' => (string) $value]
                );
            }
        }

        return $this->getSettings();
    }

    public function getBadges(): array
    {
        return Badge::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('category')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'description' => $b->description,
                'icon' => $b->icon,
                'color' => $b->color,
                'category' => $b->category,
                'criteria_type' => $b->criteria_type,
                'criteria_value' => $b->criteria_value,
                'awarded_count' => AgentBadge::where('badge_id', $b->id)->count(),
            ])
            ->toArray();
    }

    public function getMilestones(): array
    {
        return Milestone::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('target_value')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'slug' => $m->slug,
                'description' => $m->description,
                'metric' => $m->metric,
                'target_value' => $m->target_value,
                'period' => $m->period,
                'completions' => AgentMilestone::where('milestone_id', $m->id)->whereNotNull('completed_at')->count(),
            ])
            ->toArray();
    }
}
