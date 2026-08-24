<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
