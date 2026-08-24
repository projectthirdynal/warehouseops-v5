<?php

declare(strict_types=1);

namespace App\Domain\Lead\Models;

use App\Domain\Lead\Enums\LeadPoolStatus;
use App\Domain\Lead\Enums\PoolMemberStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadPool extends Model
{
    protected $table = 'lead_pools';

    protected $fillable = [
        'pool_number',
        'pool_request_id',
        'brand_name',
        'product_name',
        'business_region',
        'province',
        'city',
        'lead_age_from',
        'lead_age_to',
        'team_id',
        'approved_quantity',
        'reserved_quantity',
        'distributed_quantity',
        'distribution_method',
        'status',
        'created_by',
        'approved_by',
        'activated_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'lead_age_from' => 'integer',
        'lead_age_to' => 'integer',
        'approved_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'distributed_quantity' => 'integer',
        'activated_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'status' => LeadPoolStatus::class,
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeadPoolRequest::class, 'pool_request_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(LeadPoolMember::class, 'lead_pool_id');
    }

    public function pendingMembers(): HasMany
    {
        return $this->members()->where('status', PoolMemberStatus::PENDING);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LeadPoolStatus::READY,
            LeadPoolStatus::ACTIVE,
            LeadPoolStatus::PARTIALLY_DISTRIBUTED,
        ]);
    }

    public function scopeConsuming(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LeadPoolStatus::READY,
            LeadPoolStatus::ACTIVE,
            LeadPoolStatus::PARTIALLY_DISTRIBUTED,
        ]);
    }

    /**
     * Remaining unassigned members in this pool.
     */
    public function remainingQuantity(): int
    {
        return $this->members()
            ->where('status', PoolMemberStatus::PENDING)
            ->count();
    }
}
