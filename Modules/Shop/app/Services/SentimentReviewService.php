<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Modules\Shop\Models\Conversation;

class SentimentReviewService
{
    public function __construct(
        private readonly SentimentAnalysisService $analyzer,
    ) {}

    public function getStats(): array
    {
        $baseQuery = Conversation::query()->whereNull('merged_into_id');

        $total = (clone $baseQuery)->count();
        $positive = (clone $baseQuery)->where('sentiment', 'positive')->count();
        $neutral = (clone $baseQuery)->where('sentiment', 'neutral')->count();
        $negative = (clone $baseQuery)->where('sentiment', 'negative')->count();

        $flaggedNegative = (clone $baseQuery)
            ->where('sentiment', 'negative')
            ->where('is_flagged', true)
            ->whereNull('resolved_at')
            ->count();

        $autoFlagged = (clone $baseQuery)
            ->where('is_flagged', true)
            ->where('flag_reason', 'like', 'Negative sentiment detected%')
            ->count();

        $resolvedFlags = (clone $baseQuery)
            ->where('is_flagged', true)
            ->whereNotNull('resolved_at')
            ->count();

        // Recent negative sentiment (last 24h)
        $recentNegative = (clone $baseQuery)
            ->where('sentiment', 'negative')
            ->where('last_message_at', '>=', now()->subDay())
            ->count();

        // Sentiment trend (last 7 days)
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $counts = (clone $baseQuery)
                ->whereDate('last_message_at', $date)
                ->select('sentiment', DB::raw('count(*) as cnt'))
                ->groupBy('sentiment')
                ->pluck('cnt', 'sentiment')
                ->toArray();
            $trend[] = [
                'date' => $date,
                'positive' => $counts['positive'] ?? 0,
                'neutral' => $counts['neutral'] ?? 0,
                'negative' => $counts['negative'] ?? 0,
            ];
        }

        return [
            'total' => $total,
            'positive' => $positive,
            'neutral' => $neutral,
            'negative' => $negative,
            'positive_pct' => $total > 0 ? round(($positive / $total) * 100, 1) : 0,
            'neutral_pct' => $total > 0 ? round(($neutral / $total) * 100, 1) : 0,
            'negative_pct' => $total > 0 ? round(($negative / $total) * 100, 1) : 0,
            'flagged_negative' => $flaggedNegative,
            'auto_flagged_total' => $autoFlagged,
            'resolved_flags' => $resolvedFlags,
            'recent_negative_24h' => $recentNegative,
            'trend' => $trend,
        ];
    }

    public function getSettings(): array
    {
        return [
            'auto_flag_enabled' => (bool) SiteSetting::get('sentiment_auto_flag_enabled', true),
            'negative_threshold' => (float) SiteSetting::get('sentiment_negative_threshold', -0.15),
            'min_negative_hits' => (int) SiteSetting::get('sentiment_min_negative_hits', 2),
            'auto_unflag_enabled' => (bool) SiteSetting::get('sentiment_auto_unflag_enabled', true),
        ];
    }

    public function updateSettings(array $settings): array
    {
        if (array_key_exists('auto_flag_enabled', $settings)) {
            SiteSetting::set('sentiment_auto_flag_enabled', (bool) $settings['auto_flag_enabled']);
        }
        if (array_key_exists('negative_threshold', $settings)) {
            SiteSetting::set('sentiment_negative_threshold', (float) $settings['negative_threshold']);
        }
        if (array_key_exists('min_negative_hits', $settings)) {
            SiteSetting::set('sentiment_min_negative_hits', (int) $settings['min_negative_hits']);
        }
        if (array_key_exists('auto_unflag_enabled', $settings)) {
            SiteSetting::set('sentiment_auto_unflag_enabled', (bool) $settings['auto_unflag_enabled']);
        }

        return $this->getSettings();
    }

    public function getReviewQueue(int $limit = 50): array
    {
        $conversations = Conversation::query()
            ->whereNull('merged_into_id')
            ->where('sentiment', 'negative')
            ->whereNull('resolved_at')
            ->with([
                'facebookPage:id,page_name,page_id',
                'assignedAgent:id,name',
                'customer:id,name,phone',
            ])
            ->orderByDesc('sentiment_score')
            ->limit($limit)
            ->get([
                'id',
                'facebook_page_id',
                'customer_id',
                'assigned_agent_id',
                'status',
                'priority',
                'sentiment',
                'sentiment_score',
                'is_flagged',
                'flag_reason',
                'flagged_at',
                'last_message_preview',
                'last_message_at',
                'unread_count',
                'created_at',
            ]);

        return $conversations->map(fn (Conversation $c) => [
            'id' => $c->id,
            'page_name' => $c->facebookPage?->page_name ?? 'Unknown',
            'customer_name' => $c->customer?->name ?? 'Unknown',
            'assigned_agent' => $c->assignedAgent?->name ?? 'Unassigned',
            'status' => $c->status,
            'priority' => $c->priority,
            'sentiment' => $c->sentiment,
            'sentiment_score' => $c->sentiment_score,
            'is_flagged' => $c->is_flagged,
            'flag_reason' => $c->flag_reason,
            'flagged_at' => $c->flagged_at?->toIso8601String(),
            'last_message_preview' => $c->last_message_preview,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'unread_count' => $c->unread_count,
        ])->values()->toArray();
    }

    public function resolveFlag(int $conversationId): bool
    {
        $conversation = Conversation::query()->find($conversationId);
        if (! $conversation) {
            return false;
        }

        $conversation->forceFill([
            'is_flagged' => false,
            'flag_reason' => null,
            'flagged_at' => null,
        ])->save();

        return true;
    }

    public function bulkAnalyze(int $limit = 100): array
    {
        $conversations = Conversation::query()
            ->whereNull('merged_into_id')
            ->where(function ($q) {
                $q->whereNull('sentiment')->orWhere('sentiment', 'neutral');
            })
            ->where('last_message_at', '>=', now()->subDays(30))
            ->limit($limit)
            ->get(['id']);

        $analyzed = 0;
        $flagged = 0;

        foreach ($conversations as $conversation) {
            $recentMessages = $conversation->messages()
                ->where('direction', 'inbound')
                ->latest('sent_at')
                ->limit(10)
                ->pluck('body')
                ->filter()
                ->toArray();

            if (empty($recentMessages)) {
                continue;
            }

            $sentiment = $this->analyzer->analyze(implode(' ', $recentMessages));

            $updateData = [
                'sentiment' => $sentiment['sentiment'],
                'sentiment_score' => $sentiment['score'],
            ];

            if ($this->analyzer->shouldFlag($sentiment) && ! $conversation->is_flagged) {
                $flaggedWords = $sentiment['flagged_words'] ?? [];
                $reason = 'Negative sentiment detected';
                if (! empty($flaggedWords)) {
                    $reason .= ' (keywords: '.implode(', ', array_slice($flaggedWords, 0, 5)).')';
                }

                $updateData['is_flagged'] = true;
                $updateData['flag_reason'] = $reason;
                $updateData['flagged_at'] = now();
                $flagged++;
            }

            $conversation->forceFill($updateData)->save();
            $analyzed++;
        }

        return [
            'analyzed' => $analyzed,
            'flagged' => $flagged,
            'total' => $conversations->count(),
        ];
    }
}
