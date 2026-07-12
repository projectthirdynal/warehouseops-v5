<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'phone',
        'normalized_phone',
        'name',
        'facebook_name',
        'canonical_address',
        'landmark',
        'barangay',
        'city_municipality',
        'province',
        'region',
        'last_page_ordered_from',
        'last_order_date',
        'total_orders',
        'successful_orders',
        'returned_orders',
        'success_rate',
        'total_revenue',
        'risk_level',
        'is_blacklisted',
        'blacklist_reason',
        'blacklisted_at',
    ];

    protected $casts = [
        'success_rate' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'is_blacklisted' => 'boolean',
        'blacklisted_at' => 'datetime',
        'last_order_date' => 'datetime',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(\App\Domain\Order\Models\Order::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(\App\Domain\Shop\Models\CustomerIdentity::class);
    }
}
