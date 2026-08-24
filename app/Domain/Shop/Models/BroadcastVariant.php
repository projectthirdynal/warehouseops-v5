<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<int, array{content_type: string, title: string, payload: string}>|null $quick_replies
 */
class BroadcastVariant extends Model
{
    public const MAX_QUICK_REPLIES = 11;

    public const QUICK_REPLY_TITLE_MAX_LENGTH = 20;

    protected $fillable = [
        'broadcast_campaign_id',
        'label',
        'body',
        'quick_replies',
        'recipient_count',
        'sent_count',
        'delivered_count',
        'read_count',
        'replied_count',
        'failed_count',
    ];

    protected $casts = [
        'quick_replies' => 'array',
        'recipient_count' => 'integer',
        'sent_count' => 'integer',
        'delivered_count' => 'integer',
        'read_count' => 'integer',
        'replied_count' => 'integer',
        'failed_count' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BroadcastCampaign::class, 'broadcast_campaign_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function setQuickRepliesAttribute(?array $value): void
    {
        $normalized = $this->normalizeQuickReplies($value);

        $this->attributes['quick_replies'] = $normalized === null ? null : json_encode($normalized);
    }

    public function normalizeQuickReplies(?array $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        $normalized = [];

        foreach ($value as $reply) {
            $title = trim(is_array($reply) ? (string) ($reply['title'] ?? '') : (string) $reply);

            if ($title === '') {
                continue;
            }

            $normalized[] = [
                'content_type' => is_array($reply) ? ($reply['content_type'] ?? 'text') : 'text',
                'title' => mb_substr($title, 0, self::QUICK_REPLY_TITLE_MAX_LENGTH),
                'payload' => is_array($reply) ? (string) ($reply['payload'] ?? $title) : $title,
            ];

            if (count($normalized) >= self::MAX_QUICK_REPLIES) {
                break;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    public function hasQuickReplies(): bool
    {
        return filled($this->quick_replies);
    }

    public function replyRate(int $precision = 1): float
    {
        return $this->rate($this->replied_count, $precision);
    }

    public function deliveryRate(int $precision = 1): float
    {
        return $this->rate($this->delivered_count, $precision);
    }

    public function readRate(int $precision = 1): float
    {
        return $this->rate($this->read_count, $precision);
    }

    public function recordSent(): void
    {
        $this->increment('sent_count');
    }

    public function recordDelivered(): void
    {
        $this->increment('delivered_count');
    }

    public function recordRead(): void
    {
        $this->increment('read_count');
    }

    public function recordReplied(): void
    {
        $this->increment('replied_count');
    }

    public function recordFailed(): void
    {
        $this->increment('failed_count');
    }

    public static function determineWinner(iterable $variants): ?self
    {
        return collect($variants)
            ->filter(fn (self $variant): bool => $variant->sent_count > 0)
            ->sort(function (self $a, self $b): int {
                return [$b->replyRate(2), $b->sent_count] <=> [$a->replyRate(2), $a->sent_count];
            })
            ->first();
    }

    private function rate(?int $count, int $precision): float
    {
        $sent = $this->sent_count ?? 0;

        if ($sent === 0) {
            return 0.0;
        }

        return round((($count ?? 0) / $sent) * 100, $precision);
    }
}
