<?php

declare(strict_types=1);

namespace App\Domain\Lead\Models;

use App\Domain\Lead\Enums\PoolRequestStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeadPoolRequest extends Model
{
    protected $table = 'lead_pool_requests';

    protected $fillable = [
        'request_number',
        'requested_by',
        'team_id',
        'brand_name',
        'product_name',
        'business_region',
        'province',
        'city',
        'lead_age_from',
        'lead_age_to',
        'requested_quantity',
        'available_quantity_at_request',
        'approved_quantity',
        'distribution_method',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'lead_age_from' => 'integer',
        'lead_age_to' => 'integer',
        'requested_quantity' => 'integer',
        'available_quantity_at_request' => 'integer',
        'approved_quantity' => 'integer',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'status' => PoolRequestStatus::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            if (empty($request->request_number)) {
                $request->request_number = static::generateRequestNumber();
            }
        });
    }

    /**
     * Generate a unique request number: LP-YYYYMMDD-NNNN
     */
    public static function generateRequestNumber(): string
    {
        $prefix = 'LP-'.now()->format('Ymd').'-';
        $latest = static::where('request_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('request_number');

        $next = 1;
        if ($latest) {
            $parts = explode('-', $latest);
            $next = (int) end($parts) + 1;
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function pool(): HasOne
    {
        return $this->hasOne(LeadPool::class, 'pool_request_id');
    }

    // Scopes

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PoolRequestStatus::PENDING_APPROVAL);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', PoolRequestStatus::APPROVED);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PoolRequestStatus::PENDING_APPROVAL,
            PoolRequestStatus::APPROVED,
            PoolRequestStatus::PARTIALLY_DISTRIBUTED,
        ]);
    }

    /**
     * Convert the request's filter fields into the array format expected
     * by LeadEligibilityService::countEligible().
     *
     * @return array{brand?: ?string, product?: ?string, business_region?: ?string, province?: ?string, city?: ?string, age_from?: ?int, age_to?: ?int}
     */
    public function toEligibilityFilters(): array
    {
        $filters = [];

        if ($this->brand_name) {
            $filters['brand'] = $this->brand_name;
        }
        if ($this->product_name) {
            $filters['product'] = $this->product_name;
        }
        if ($this->business_region) {
            $filters['business_region'] = $this->business_region;
        }
        if ($this->province) {
            $filters['province'] = $this->province;
        }
        if ($this->city) {
            $filters['city'] = $this->city;
        }
        if ($this->lead_age_from !== null) {
            $filters['age_from'] = $this->lead_age_from;
        }
        if ($this->lead_age_to !== null) {
            $filters['age_to'] = $this->lead_age_to;
        }

        return $filters;
    }
}
