<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk messaging campaign.
 *
 * Lifecycle: draft → scheduled → sending → completed, with cancelled
 * reachable from every non-terminal state. A/B campaigns carry two or more
 * variants and a split_percentage controlling how recipients are divided.
 *
 * Targeting is a free-form JSON object consumed by
 * BroadcastCampaignService::buildTargetingQuery(); supported keys are
 * listed in TARGETING_KEYS.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int|null $facebook_page_id
 * @property string $status
 * @property array|null $targeting
 * @property string $split_type
 * @property int $split_percentage
 * @property int $total_recipients
 * @property int $sent_count
 * @property int $delivered_count
 * @property int $read_count
 * @property int $replied_count
 * @property int $failed_count
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $created_by
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

    /**
     * Cancelled campaigns may not resume; completed ones are immutable.
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SCHEDULED, self::STATUS_SENDING, self::STATUS_CANCELLED],
        self::STATUS_SCHEDULED => [self::STATUS_DRAFT, self::STATUS_SENDING, self::STATUS_CANCELLED],
        self::STATUS_SENDING => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    public const SPLIT_SINGLE = 'single';

    public const SPLIT_AB_TEST = 'ab_test';

    public const SPLIT_TYPES = [
        self::SPLIT_SINGLE,
        self::SPLIT_AB_TEST,
    ];

    /**
     * Recognised targeting configuration keys (see buildTargetingQuery).
     */
    public const TARGETING_KEYS = [
        'page_id',
        'assigned_agent_id',
        'status',
        'tags',
        'risk_level',
        'opt_in_only',
        'has_ordered',
        'min_order_count',
    ];

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
        'split_percentage' => 'integer',
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'delivered_count' => 'integer',
        'read_count' => 'integer',
        'replied_count' => 'integer',
        'failed_count' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** @param  array<int, string>|string  $status */
    public function scopeOfStatus(Builder $query, array|string $status): Builder
    {
        return $query->whereIn('status', (array) $status);
    }

    /** Scheduled campaigns whose send time has arrived. */
    public function scopeDueToSend(Builder $query, ?Carbon $now = null): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', $now ?? now());
    }

    /** Campaigns still open for editing or cancellation. */
    public function scopeNotTerminal(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function canTransitionTo(string $targetStatus): bool
    {
        if ($this->status === $targetStatus) {
            return true;
        }

        return in_array($targetStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Guarded status change; persists only when the transition is legal.
     */
    public function transitionTo(string $targetStatus): bool
    {
        if (! $this->canTransitionTo($targetStatus)) {
            return false;
        }

        $this->forceFill(['status' => $targetStatus])->save();

        return true;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isAbTest(): bool
    {
        return $this->split_type === self::SPLIT_AB_TEST;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    /** Replied ÷ sent, percent. */
    public function replyRate(): float
    {
        return $this->sent_count > 0
            ? round(($this->replied_count / $this->sent_count) * 100, 1)
            : 0.0;
    }

    /** Failed ÷ sent, percent. */
    public function failureRate(): float
    {
        return $this->sent_count > 0
            ? round(($this->failed_count / $this->sent_count) * 100, 1)
            : 0.0;
    }

    /**
     * Recompute aggregate counters from the recipient rows (source of truth),
     * e.g. after out-of-band delivery webhooks mutate them.
     */
    public function refreshCounters(): void
    {
        DB::transaction(function (): void {
            $counts = $this->recipients()
                ->selectRaw('COUNT(*) as total_recipients')
                ->selectRaw("SUM(CASE WHEN status IN ('sent','delivered','read','replied') THEN 1 ELSE 0 END) as sent_count")
                ->selectRaw("SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count")
                ->selectRaw("SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_count")
                ->selectRaw("SUM(CASE WHEN status = 'replied' THEN 1 ELSE 0 END) as replied_count")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
                ->first();

            $this->forceFill([
                'total_recipients' => (int) ($counts?->total_recipients ?? 0),
                'sent_count' => (int) ($counts?->sent_count ?? 0),
                'delivered_count' => (int) ($counts?->delivered_count ?? 0),
                'read_count' => (int) ($counts?->read_count ?? 0),
                'replied_count' => (int) ($counts?->replied_count ?? 0),
                'failed_count' => (int) ($counts?->failed_count ?? 0),
            ])->save();
        });
    }
}
