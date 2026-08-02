<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReplyTemplateAbTest extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'name',
        'description',
        'status',
        'created_by',
        'start_at',
        'end_at',
        'winning_variant_id',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ReplyTemplateAbVariant::class, 'ab_test_id');
    }

    public function winningVariant(): BelongsTo
    {
        return $this->belongsTo(ReplyTemplateAbVariant::class, 'winning_variant_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Select a variant based on weighted random distribution.
     */
    public function selectVariant(): ?ReplyTemplateAbVariant
    {
        $variants = $this->variants()->with('replyTemplate')->get();
        if ($variants->isEmpty()) {
            return null;
        }

        $totalWeight = $variants->sum('weight');
        if ($totalWeight <= 0) {
            return $variants->first();
        }

        $random = mt_rand(1, $totalWeight);
        $cumulative = 0;
        foreach ($variants as $variant) {
            $cumulative += $variant->weight;
            if ($random <= $cumulative) {
                return $variant;
            }
        }

        return $variants->last();
    }
}
