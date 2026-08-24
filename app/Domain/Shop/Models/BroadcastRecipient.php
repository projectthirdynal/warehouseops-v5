<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BroadcastRecipient Model
 *
 * Tracks individual recipients within broadcast campaigns for comprehensive
 * delivery analytics and lifecycle management. This model provides detailed
 * tracking of message delivery status from initial send through final delivery,
 * read receipts, and customer responses.
 *
 * ## Core Features
 *
 * - **Individual Recipient Tracking**: Each conversation/customer is tracked separately
 * - **Status Lifecycle Management**: Complete status transitions from pending to final states
 * - **Delivery Analytics**: Timestamp tracking for each status transition
 * - **Error Handling**: Detailed error message logging for failed deliveries
 * - **Retry Logic**: Built-in retry counter for failed delivery attempts
 * - **A/B Testing Support**: Links to specific broadcast variants for testing analysis
 *
 * ## Status States
 *
 * - `pending`: Initial state, awaiting delivery
 * - `sent`: Message successfully sent to Meta API
 * - `delivered`: Message delivered to recipient's device
 * - `read`: Recipient has opened/read the message
 * - `replied`: Recipient responded to the message
 * - `failed`: Delivery failed with error details
 * - `skipped`: Intentionally skipped (e.g., missing token, invalid recipient)
 *
 * ## Relationships
 *
 * - Belongs to BroadcastCampaign (parent campaign)
 * - Belongs to BroadcastVariant (specific A/B test variant)
 * - Belongs to Conversation (messaging thread)
 * - Belongs to Message (actual sent message)
 * - Belongs to Customer (recipient customer)
 *
 * ## Usage Example
 *
 * ```php
 * // Create recipient for campaign
 * $recipient = BroadcastRecipient::create([
 *     'broadcast_campaign_id' => $campaign->id,
 *     'broadcast_variant_id' => $variant->id,
 *     'conversation_id' => $conversation->id,
 *     'customer_id' => $customer->id,
 *     'status' => BroadcastRecipient::STATUS_PENDING,
 * ]);
 *
 * // Track delivery lifecycle
 * $recipient->markSent($messageId);
 * $recipient->markDelivered();
 * $recipient->markRead();
 * $recipient->markReplied();
 *
 * // Handle failures with retry logic
 * $recipient->markFailed('Invalid PSID');
 * if ($recipient->retry_count < 3) {
 *     // Retry delivery
 * }
 * ```
 *
 * @property int $id
 * @property int $broadcast_campaign_id Parent campaign ID
 * @property int $broadcast_variant_id A/B test variant ID
 * @property int $conversation_id Associated conversation ID
 * @property int|null $customer_id Customer ID
 * @property string $status Current delivery status
 * @property string|null $error_message Error details if failed
 * @property int|null $message_id Link to sent message
 * @property \Illuminate\Support\Carbon|null $sent_at When message was sent
 * @property \Illuminate\Support\Carbon|null $delivered_at When message was delivered
 * @property \Illuminate\Support\Carbon|null $read_at When message was read
 * @property \Illuminate\Support\Carbon|null $replied_at When customer replied
 * @property \Illuminate\Support\Carbon|null $failed_at When delivery failed
 * @property int $retry_count Number of retry attempts
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
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
        'delivered_at',
        'read_at',
        'replied_at',
        'failed_at',
        'retry_count',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'replied_at' => 'datetime',
        'failed_at' => 'datetime',
        'retry_count' => 'integer',
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
        $this->forceFill([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ])->save();
    }

    public function markRead(): void
    {
        $this->forceFill([
            'status' => self::STATUS_READ,
            'read_at' => now(),
        ])->save();
    }

    public function markReplied(): void
    {
        $this->forceFill([
            'status' => self::STATUS_REPLIED,
            'replied_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
            'failed_at' => now(),
            'retry_count' => ($this->retry_count ?? 0) + 1,
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
