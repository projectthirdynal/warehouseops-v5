<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BroadcastCampaign Model
 *
 * Main campaign model for bulk messaging operations with comprehensive
 * A/B testing support and delivery lifecycle management.
 *
 * ## Status Constants
 *
 * - STATUS_DRAFT: Campaign in preparation
 * - STATUS_SCHEDULED: Campaign scheduled for future delivery
 * - STATUS_SENDING: Campaign currently being sent
 * - STATUS_COMPLETED: Campaign delivery finished
 * - STATUS_CANCELLED: Campaign cancelled before completion
 *
 * ## Split Types
 *
 * - SPLIT_SINGLE: Single message variant for all recipients
 * - SPLIT_AB_TEST: A/B testing with multiple variants
 *
 * ## Guards
 *
 * - canBeSent(): Checks if campaign can transition to sending
 * - canBeCancelled(): Checks if campaign can be cancelled
 * - isAbTest(): Determines if this is an A/B test campaign
 * - replyRate(): Calculates overall campaign reply rate
 * - winningVariant(): Returns best performing variant for A/B tests
 * - scopeScheduledAndDue(): Query scope for scheduler wiring
 *
 * @property int $id
 * @property string $name Campaign name
 * @property string|null $description Campaign description
 * @property int|null $facebook_page_id Associated Facebook page
 * @property string $status Current campaign status
 * @property array|null $targeting Targeting configuration
 * @property string $split_type Campaign split type (single/ab_test)
 * @property int $split_percentage A/B test split percentage
 * @property int $total_recipients Total recipient count
 * @property int $sent_count Total messages sent
 * @property int $delivered_count Total messages delivered
 * @property int $read_count Total messages read
 * @property int $replied_count Total replies received
 * @property int $failed_count Total failed deliveries
 * @property \Illuminate\Support\Carbon|null $scheduled_at When campaign is scheduled
 * @property \Illuminate\Support\Carbon|null $started_at When sending started
 * @property \Illuminate\Support\Carbon|null $completed_at When sending completed
 * @property int $created_by User who created campaign
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class BroadcastCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_SENDING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const SPLIT_SINGLE = 'single';

    public const SPLIT_AB_TEST = 'ab_test';

    protected $fillable = [
        'name',
        'description',
        'facebook_page_id',
        'status',
        'targeting',
        'split_type',
        'split_percentage',
        'total_recipients',
        'sent_count',
        'delivered_count',
        'read_count',
        'replied_count',
        'failed_count',
        'scheduled_at',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'targeting' => 'array',
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'delivered_count' => 'integer',
        'read_count' => 'integer',
        'replied_count' => 'integer',
        'failed_count' => 'integer',
        'split_percentage' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(BroadcastVariant::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function replyRate(int $precision = 1): float
    {
        $sent = $this->sent_count ?? 0;

        if ($sent === 0) {
            return 0.0;
        }

        return round((($this->replied_count ?? 0) / $sent) * 100, $precision);
    }

    public function canBeSent(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }

    public function canBeCancelled(): bool
    {
        return ! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    public function isAbTest(): bool
    {
        return $this->split_type === self::SPLIT_AB_TEST;
    }

    public function winningVariant(): ?BroadcastVariant
    {
        return BroadcastVariant::determineWinner($this->variants);
    }

    public function scopeScheduledAndDue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }
}
