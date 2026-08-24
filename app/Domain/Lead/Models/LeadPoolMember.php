<?php

declare(strict_types=1);

namespace App\Domain\Lead\Models;

use App\Domain\Lead\Enums\PoolMemberStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPoolMember extends Model
{
    protected $table = 'lead_pool_members';

    protected $fillable = [
        'lead_pool_id',
        'lead_id',
        'status',
        'added_at',
        'assigned_at',
        'removed_at',
        'removal_reason',
    ];

    protected $casts = [
        'added_at' => 'datetime',
        'assigned_at' => 'datetime',
        'removed_at' => 'datetime',
        'status' => PoolMemberStatus::class,
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(LeadPool::class, 'lead_pool_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PoolMemberStatus::PENDING);
    }

    public function scopeAssigned(Builder $query): Builder
    {
        return $query->where('status', PoolMemberStatus::ASSIGNED);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [PoolMemberStatus::PENDING, PoolMemberStatus::ASSIGNED]);
    }
}
