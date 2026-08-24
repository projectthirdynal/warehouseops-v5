<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastRecipient extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_DELIVERED,
        self::STATUS_READ,
        self::STATUS_REPLIED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    protected $fillable = [
        'broadcast_campaign_id',
        'broadcast_variant_id',
        'conversation_id',
        'customer_id',
        'status',
        'error_message',
        'message_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BroadcastCampaign::class, 'broadcast_campaign_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(BroadcastVariant::class, 'broadcast_variant_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function markSent(int $messageId): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'message_id' => $messageId,
            'sent_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function markDelivered(): void
    {
        $this->forceFill(['status' => self::STATUS_DELIVERED])->save();
    }

    public function markRead(): void
    {
        $this->forceFill(['status' => self::STATUS_READ])->save();
    }

    public function markReplied(): void
    {
        $this->forceFill(['status' => self::STATUS_REPLIED])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
        ])->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_SKIPPED,
            'error_message' => $reason,
        ])->save();
    }
}
