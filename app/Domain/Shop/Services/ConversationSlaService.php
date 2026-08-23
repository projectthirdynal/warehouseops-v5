<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\Conversation;
use App\Models\SiteSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * SLA monitoring for shop conversations.
 *
 * Two dimensions are tracked per open conversation:
 *
 *  1. First response (status "new"): the clock runs from created_at until
 *     first_response_at lands. A timely response satisfies the SLA forever;
 *     a late response is recorded as breached even after the fact, and an
 *     unanswered conversation breaches once the threshold elapses.
 *  2. Attention windows ("assigned", "awaiting_customer"): the clock re-arms
 *     from the last thread activity so a conversation cannot sit untouched
 *     past its status threshold.
 *
 * Thresholds default to Conversation::SLA_THRESHOLDS and may be overridden
 * per status via the "conversation_sla_thresholds" SiteSetting (JSON), where
 * an explicit null disables monitoring for that status.
 */
class ConversationSlaService
{
    public const STATE_NONE = 'none';

    public const STATE_PENDING = 'pending';

    public const STATE_WARNING = 'warning';

    public const STATE_OK = 'ok';

    public const STATE_BREACHED = 'breached';

    // -------------------------------------------------------------------------
    // Public API (ShopController endpoints depend on these signatures)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $thresholds = Conversation::slaThresholds();
        $warningPercent = $this->warningPercent();
        $today = today();
        $yesterday = today()->subDay();

        $active = Conversation::query()
            ->whereIn('status', Conversation::ACTIVE_STATUSES)
            ->whereNull('merged_into_id')
            ->get(['id', 'status', 'created_at', 'updated_at', 'first_response_at', 'assigned_agent_id']);

        $breached = 0;
        $warning = 0;
        $ok = 0;
        $unresponded = 0;
        $respondedLate = 0;

        foreach ($active as $conv) {
            $snapshot = $this->evaluate($conv, $thresholds, $warningPercent);

            if ($conv->first_response_at === null && $snapshot['response_minutes'] === null) {
                $unresponded++;
            }

            if ($snapshot['responded_late']) {
                $respondedLate++;
            }

            match ($snapshot['state']) {
                self::STATE_BREACHED => $breached++,
                self::STATE_WARNING => $warning++,
                self::STATE_OK, self::STATE_PENDING => $ok++,
                default => null,
            };
        }

        $frStats = Conversation::query()
            ->whereDate('first_response_at', $today)
            ->whereNotNull('first_response_time_seconds')
            ->selectRaw('COUNT(*) as count, AVG(first_response_time_seconds) as avg_seconds, MIN(first_response_time_seconds) as min_seconds, MAX(first_response_time_seconds) as max_seconds')
            ->first();

        $resStats = Conversation::query()
            ->whereDate('resolved_at', $today)
            ->whereNotNull('resolution_time_seconds')
            ->selectRaw('COUNT(*) as count, AVG(resolution_time_seconds) as avg_seconds, MIN(resolution_time_seconds) as min_seconds, MAX(resolution_time_seconds) as max_seconds')
            ->first();

        $yesterdayFr = Conversation::query()
            ->whereDate('first_response_at', $yesterday)
            ->whereNotNull('first_response_time_seconds')
            ->avg('first_response_time_seconds');

        $yesterdayRes = Conversation::query()
            ->whereDate('resolved_at', $yesterday)
            ->whereNotNull('resolution_time_seconds')
            ->avg('resolution_time_seconds');

        return [
            'active_total' => $active->count(),
            'breached' => $breached,
            'warning' => $warning,
            'ok' => $ok,
            'unresponded' => $unresponded,
            'responded_late' => $respondedLate,
            'healthy_count' => $ok,
            'breach_rate' => $active->count() > 0 ? round(($breached / $active->count()) * 100, 1) : 0,
            'first_response' => [
                'count' => (int) ($frStats?->count ?? 0),
                'avg_seconds' => $frStats?->avg_seconds ? (int) $frStats->avg_seconds : null,
                'min_seconds' => $frStats?->min_seconds ? (int) $frStats->min_seconds : null,
                'max_seconds' => $frStats?->max_seconds ? (int) $frStats->max_seconds : null,
                'yesterday_avg_seconds' => $yesterdayFr ? (int) $yesterdayFr : null,
            ],
            'resolution' => [
                'count' => (int) ($resStats?->count ?? 0),
                'avg_seconds' => $resStats?->avg_seconds ? (int) $resStats->avg_seconds : null,
                'min_seconds' => $resStats?->min_seconds ? (int) $resStats->min_seconds : null,
                'max_seconds' => $resStats?->max_seconds ? (int) $resStats->max_seconds : null,
                'yesterday_avg_seconds' => $yesterdayRes ? (int) $yesterdayRes : null,
            ],
            'trend' => $this->trend(7),
            'agent_performance' => $this->agentPerformance($today),
            'thresholds' => $thresholds,
            'warning_percent' => $warningPercent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return [
            'thresholds' => Conversation::slaThresholds(),
            'warning_percent' => $this->warningPercent(),
            'breach_notifications' => SiteSetting::get('conversation_sla_breach_notifications', '1') === '1',
            'breach_notify_channel' => SiteSetting::get('conversation_sla_breach_notify_channel', 'log'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateSettings(array $data): array
    {
        if (isset($data['thresholds']) && is_array($data['thresholds'])) {
            $cleaned = [];
            foreach ($data['thresholds'] as $status => $minutes) {
                if (in_array($status, Conversation::STATUSES, true)) {
                    $cleaned[$status] = $minutes === null ? null : max(1, (int) $minutes);
                }
            }
            SiteSetting::set('conversation_sla_thresholds', json_encode($cleaned));
        }
        if (isset($data['warning_percent'])) {
            SiteSetting::set('conversation_sla_warning_percent', (string) max(50, min(99, (int) $data['warning_percent'])));
        }
        if (isset($data['breach_notifications'])) {
            SiteSetting::set('conversation_sla_breach_notifications', $data['breach_notifications'] ? '1' : '0');
        }
        if (isset($data['breach_notify_channel'])) {
            SiteSetting::set('conversation_sla_breach_notify_channel', $data['breach_notify_channel']);
        }

        return $this->getSettings();
    }

    /**
     * Conversations currently breaching their SLA, worst offenders first.
     *
     * @return list<array<string, mixed>>
     */
    public function getBreachedConversations(int $limit = 50): array
    {
        $thresholds = Conversation::slaThresholds();
        $warningPercent = $this->warningPercent();

        $snapshots = [];
        foreach ($this->monitoredQuery()
            ->with(['facebookPage:id,page_name', 'assignedAgent:id,name', 'customer:id,name', 'identity:id,display_name'])
            ->get() as $conversation) {
            $snapshots[$conversation->id] = ['conv' => $conversation, 'sla' => $this->evaluate($conversation, $thresholds, $warningPercent)];
        }

        $breached = collect($snapshots)
            ->filter(fn (array $entry) => $entry['sla']['state'] === self::STATE_BREACHED)
            ->sortByDesc(fn (array $entry) => (int) $entry['sla']['minutes_elapsed'] - (int) ($entry['sla']['threshold_minutes'] ?? 0))
            ->take($limit);

        return $breached->map(function (array $entry) {
            $conv = $entry['conv'];
            $snapshot = $entry['sla'];
            $threshold = $snapshot['threshold_minutes'];

            return [
                'id' => $conv->id,
                'status' => $conv->status,
                'customer_name' => $conv->customer?->name ?? $conv->identity?->display_name ?? 'Unknown',
                'page_name' => $conv->facebookPage?->page_name,
                'agent_name' => $conv->assignedAgent?->name,
                'elapsed_minutes' => $snapshot['minutes_elapsed'],
                'threshold_minutes' => $threshold,
                'over_minutes' => $snapshot['minutes_elapsed'] - ($threshold ?? 0),
                'first_response_at' => $conv->first_response_at?->toIso8601String(),
                'created_at' => $conv->created_at?->toIso8601String(),
                'last_message_preview' => $conv->last_message_preview,
                'due_at' => $snapshot['due_at']?->toIso8601String(),
                'state' => $snapshot['state'],
            ];
        })->values()->toArray();
    }

    /**
     * Log-channel breach alerts. Returns the number currently breaching.
     * Safe to run repeatedly: one grouped summary per sweep, top offenders attached.
     */
    public function checkBreachAlerts(int $limit = 100): int
    {
        if (SiteSetting::get('conversation_sla_breach_notifications', '1') !== '1') {
            return 0;
        }

        $breached = $this->getBreachedConversations($limit);
        $count = count($breached);

        if ($count > 0) {
            $channel = SiteSetting::get('conversation_sla_breach_notify_channel', 'log');
            if ($channel === 'log') {
                Log::warning("SLA breach alert: {$count} conversations breached thresholds", [
                    'breached_count' => $count,
                    'top_breaches' => array_slice($breached, 0, 5),
                ]);
            }
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Evaluation core
    // -------------------------------------------------------------------------

    /**
     * Full SLA snapshot for one conversation.
     *
     * @param  array<string, int|null>|null  $thresholds  pass through to avoid repeated setting reads
     * @return array{state: string, threshold_minutes: int|null, minutes_elapsed: int|float|null, due_at: CarbonInterface|null, response_minutes: int|float|null, responded_late: bool}
     */
    public function evaluate(Conversation $conversation, ?array $thresholds = null, ?int $warningPercent = null, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $thresholds ??= Conversation::slaThresholds();
        $defaultThreshold = Conversation::SLA_THRESHOLDS[$conversation->status] ?? null;
        $threshold = array_key_exists($conversation->status, $thresholds)
            ? $thresholds[$conversation->status]
            : $defaultThreshold;

        if ($threshold === null) {
            return [
                'state' => self::STATE_NONE,
                'threshold_minutes' => null,
                'minutes_elapsed' => null,
                'due_at' => null,
                'response_minutes' => null,
                'responded_late' => false,
            ];
        }

        $warningPercent ??= $this->warningPercent();

        // Dimension 1: first-response timeliness governs "new" threads.
        if ($conversation->status === Conversation::STATUS_NEW) {
            return $this->classifyFirstResponse(
                $conversation->created_at,
                $conversation->first_response_at,
                (int) $threshold,
                $warningPercent,
                $now,
            );
        }
        // Dimension 2: attention window anchored at last relevant activity
        // (no assigned_at column exists; updated_at approximates it).
        $reference = match ($conversation->status) {
            Conversation::STATUS_ASSIGNED => $conversation->updated_at,
            Conversation::STATUS_AWAITING_CUSTOMER => $conversation->last_message_at ?? $conversation->updated_at,
            default => null,
        };

        if ($reference === null) {
            return [
                'state' => self::STATE_NONE,
                'threshold_minutes' => (int) $threshold,
                'minutes_elapsed' => null,
                'due_at' => null,
                'response_minutes' => null,
                'responded_late' => false,
            ];
        }

        $elapsed = max(0, (int) $reference->diffInMinutes($now));
        $dueAt = $reference->copy()->addMinutes((int) $threshold);
        $state = $this->windowState($elapsed, (int) $threshold, $warningPercent);

        return [
            'state' => $state,
            'threshold_minutes' => (int) $threshold,
            'minutes_elapsed' => $elapsed,
            'due_at' => $dueAt,
            'response_minutes' => $conversation->first_response_at !== null
                ? max(0, (int) $conversation->created_at->diffInMinutes($conversation->first_response_at))
                : null,
            'responded_late' => false,
        ];
    }

    /**
     * First-response classification.
     *
     * A response that arrived within the threshold leaves the thread "ok"
     * permanently — the clock does NOT keep running afterwards. A response
     * that arrived late records "breached" retroactively. No response yet
     * walks pending → warning → breached as the window elapses.
     *
     * @return array{state: string, threshold_minutes: int, minutes_elapsed: int, due_at: CarbonInterface|null, response_minutes: int|null, responded_late: bool}
     */
    public function classifyFirstResponse(?CarbonInterface $createdAt, ?CarbonInterface $firstResponseAt, int $thresholdMinutes, int $warningPercent = 80, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $dueAt = $createdAt?->copy()->addMinutes($thresholdMinutes);

        if ($createdAt === null || $dueAt === null) {
            return [
                'state' => self::STATE_NONE,
                'threshold_minutes' => $thresholdMinutes,
                'minutes_elapsed' => 0,
                'due_at' => null,
                'response_minutes' => null,
                'responded_late' => false,
            ];
        }

        $responseMinutes = $firstResponseAt !== null
            ? max(0, (int) $createdAt->diffInMinutes($firstResponseAt))
            : null;

        if ($responseMinutes !== null) {
            return [
                'state' => $responseMinutes >= $thresholdMinutes ? self::STATE_BREACHED : self::STATE_OK,
                'threshold_minutes' => $thresholdMinutes,
                'minutes_elapsed' => $responseMinutes,
                'due_at' => $dueAt,
                'response_minutes' => $responseMinutes,
                'responded_late' => $responseMinutes >= $thresholdMinutes,
            ];
        }

        $elapsed = max(0, (int) $createdAt->diffInMinutes($now));

        return [
            'state' => $this->windowState($elapsed, $thresholdMinutes, $warningPercent),
            'threshold_minutes' => $thresholdMinutes,
            'minutes_elapsed' => $elapsed,
            'due_at' => $dueAt,
            'response_minutes' => null,
            'responded_late' => false,
        ];
    }

    /** pending → warning → breached against an elapsed-minutes window. */
    private function windowState(int $elapsed, int $thresholdMinutes, int $warningPercent): string
    {
        return match (true) {
            $elapsed >= $thresholdMinutes => self::STATE_BREACHED,
            $elapsed >= (int) ($thresholdMinutes * $warningPercent / 100) => self::STATE_WARNING,
            default => self::STATE_PENDING,
        };
    }

    public function isBreached(Conversation $conversation, ?CarbonInterface $now = null): bool
    {
        return $this->evaluate($conversation, now: $now)['state'] === self::STATE_BREACHED;
    }

    public function isWarning(Conversation $conversation, ?CarbonInterface $now = null): bool
    {
        return in_array($this->evaluate($conversation, now: $now)['state'], [self::STATE_WARNING, self::STATE_BREACHED], true);
    }

    /**
     * Active, unmerged conversations old enough to possibly be at risk.
     *
     * Prefilter guarantees: a thread younger than the shortest threshold's
     * warning point cannot be breaching, so fresh UNANSWERED ones are skipped.
     * Answered threads are always kept — a late response is a breach however
     * recent it is — and classification happens on the slim result set.
     */
    private function monitoredQuery()
    {
        $thresholds = Conversation::slaThresholds();
        $activeThresholds = array_filter(
            array_intersect_key($thresholds, array_flip(Conversation::ACTIVE_STATUSES)),
            fn ($minutes) => is_int($minutes) && $minutes > 0,
        );

        $query = Conversation::query()
            ->whereIn('status', Conversation::ACTIVE_STATUSES)
            ->whereNull('merged_into_id');

        if ($activeThresholds !== []) {
            $cutoff = now()->subMinutes((int) floor(min($activeThresholds) * $this->warningPercent() / 100));
            $query->where(function ($q) use ($cutoff) {
                $q->whereNotNull('first_response_at')
                    ->orWhere('created_at', '<=', $cutoff);
            });
        }

        return $query;
    }

    // -------------------------------------------------------------------------
    // Stats support
    // -------------------------------------------------------------------------

    /**
     * Daily first-response / resolution averages for charts.
     *
     * @return list<array{date: string, avg_first_response_seconds: int|null, responded: int, avg_resolution_seconds: int|null, resolved: int, created: int}>
     */
    protected function trend(int $days): array
    {
        $trend = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = today()->subDays($i);

            $dayFr = Conversation::query()
                ->whereDate('first_response_at', $date)
                ->whereNotNull('first_response_time_seconds')
                ->selectRaw('AVG(first_response_time_seconds) as avg_fr, COUNT(*) as responded')
                ->first();

            $dayRes = Conversation::query()
                ->whereDate('resolved_at', $date)
                ->whereNotNull('resolution_time_seconds')
                ->selectRaw('AVG(resolution_time_seconds) as avg_res, COUNT(*) as resolved')
                ->first();

            $trend[] = [
                'date' => $date->toDateString(),
                'avg_first_response_seconds' => $dayFr?->avg_fr ? (int) $dayFr->avg_fr : null,
                'responded' => (int) ($dayFr?->responded ?? 0),
                'avg_resolution_seconds' => $dayRes?->avg_res ? (int) $dayRes->avg_res : null,
                'resolved' => (int) ($dayRes?->resolved ?? 0),
                'created' => Conversation::query()->whereDate('created_at', $date)->count(),
            ];
        }

        return $trend;
    }

    /**
     * Agents ranked by fastest average first response on the given day.
     *
     * @return list<array<string, mixed>>
     */
    protected function agentPerformance(CarbonInterface $day): array
    {
        $agentFr = Conversation::query()
            ->whereDate('first_response_at', $day)
            ->whereNotNull('first_response_time_seconds')
            ->whereNotNull('assigned_agent_id')
            ->selectRaw('assigned_agent_id, AVG(first_response_time_seconds) as avg_fr, COUNT(*) as count')
            ->groupBy('assigned_agent_id')
            ->orderBy('avg_fr')
            ->limit(10)
            ->get();

        $agentNames = User::query()->whereIn('id', $agentFr->pluck('assigned_agent_id'))->pluck('name', 'id');

        $stats = [];

        foreach ($agentFr as $row) {
            $stats[] = [
                'agent_id' => $row->assigned_agent_id,
                'agent_name' => $agentNames[$row->assigned_agent_id] ?? "Agent #{$row->assigned_agent_id}",
                'avg_first_response_seconds' => (int) $row->avg_fr,
                'responded_count' => (int) $row->count,
            ];
        }

        return $stats;
    }

    private function warningPercent(): int
    {
        return (int) SiteSetting::get('conversation_sla_warning_percent', (string) Conversation::SLA_WARNING_PERCENT);
    }
}
