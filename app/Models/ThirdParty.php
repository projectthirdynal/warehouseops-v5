<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThirdParty extends Model
{
    use HasFactory, SoftDeletes;

    // Type constants
    const TYPE_CUSTOMER  = 'customer';
    const TYPE_SUPPLIER  = 'supplier';
    const TYPE_PROSPECT  = 'prospect';
    const TYPE_PARTNER   = 'partner';
    const TYPE_BOTH      = 'both';

    // Status constants
    const STATUS_ACTIVE      = 'active';
    const STATUS_INACTIVE    = 'inactive';
    const STATUS_BLACKLISTED = 'blacklisted';
    const STATUS_PROSPECT    = 'prospect';

    // Risk constants
    const RISK_LOW         = 'LOW';
    const RISK_MEDIUM      = 'MEDIUM';
    const RISK_HIGH        = 'HIGH';
    const RISK_BLACKLISTED = 'BLACKLISTED';

    protected $fillable = [
        'ref',
        'name',
        'alias',
        'type',
        'email',
        'phone',
        'phone_alt',
        'website',
        'tax_id',
        'industry',
        'currency',
        'payment_terms',
        'credit_limit',
        'address_line1',
        'city',
        'state_province',
        'postal_code',
        'country',
        'status',
        'source',
        'assigned_to',
        'notes',
        'tags',
        'risk_level',
        'is_blacklisted',
        'blacklist_reason',
        'blacklisted_at',
        'total_orders',
        'successful_orders',
        'returned_orders',
        'total_revenue',
        'success_rate',
        'last_order_date',
        'customer_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tags'             => 'array',
        'credit_limit'     => 'decimal:2',
        'total_revenue'    => 'decimal:2',
        'success_rate'     => 'decimal:2',
        'is_blacklisted'   => 'boolean',
        'blacklisted_at'   => 'datetime',
        'last_order_date'  => 'datetime',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function primaryContact(): HasMany
    {
        return $this->hasMany(Contact::class)->where('is_primary', true);
    }

    public function defaultBillingAddress(): HasMany
    {
        return $this->hasMany(Address::class)->where('type', 'billing')->where('is_default', true);
    }

    public function defaultShippingAddress(): HasMany
    {
        return $this->hasMany(Address::class)->where('type', 'shipping')->where('is_default', true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->alias ? "{$this->name} ({$this->alias})" : $this->name;
    }

    public function getIsCustomerAttribute(): bool
    {
        return in_array($this->type, [self::TYPE_CUSTOMER, self::TYPE_BOTH]);
    }

    public function getIsSupplierAttribute(): bool
    {
        return in_array($this->type, [self::TYPE_SUPPLIER, self::TYPE_BOTH]);
    }

    public function scopeCustomers($query)
    {
        return $query->whereIn('type', [self::TYPE_CUSTOMER, self::TYPE_BOTH]);
    }

    public function scopeSuppliers($query)
    {
        return $query->whereIn('type', [self::TYPE_SUPPLIER, self::TYPE_BOTH]);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeSearch($query, string $term)
    {
        $like = '%' . mb_strtolower($term) . '%';

        return $query->where(function ($q) use ($like) {
            $q->whereRaw('LOWER(name) LIKE ?', [$like])
              ->orWhereRaw('LOWER(alias) LIKE ?', [$like])
              ->orWhereRaw('LOWER(phone) LIKE ?', [$like])
              ->orWhereRaw('LOWER(email) LIKE ?', [$like])
              ->orWhereRaw('LOWER(ref) LIKE ?', [$like]);
        });
    }

    public function getFullAddressAttribute(): ?string
    {
        $parts = array_filter([
            $this->street,
            $this->barangay,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }
}
