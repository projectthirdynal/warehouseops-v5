<?php

namespace App\Models;

use Modules\Orders\Models\Order;
use Modules\Shop\Models\CustomerIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'phone',
        'normalized_phone',
        'name',
        'facebook_name',
        'profile_image_path',
        'tags',
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
        'average_order_value',
        'preferred_courier',
        'payment_method',
        'preferred_contact_method',
        'preferred_contact_time',
        'marketing_opt_out',
        'language_preference',
        'risk_level',
        'is_blacklisted',
        'blacklist_reason',
        'blacklisted_at',
    ];

    protected $casts = [
        'success_rate' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'average_order_value' => 'decimal:2',
        'is_blacklisted' => 'boolean',
        'marketing_opt_out' => 'boolean',
        'blacklisted_at' => 'datetime',
        'last_order_date' => 'datetime',
        'tags' => 'array',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default')->orderByDesc('used_at')->orderByDesc('id');
    }

    public function defaultAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default', true);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->latest('created_at');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(CustomerAuditLog::class)->latest('created_at');
    }
}
