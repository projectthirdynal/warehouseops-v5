<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'label',
        'canonical_address',
        'landmark',
        'barangay',
        'city_municipality',
        'province',
        'region',
        'postal_code',
        'country',
        'contact_name',
        'contact_phone',
        'is_default',
        'source',
        'used_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'used_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Ensure only one default address per customer.
     */
    public static function setDefault(Customer $customer, CustomerAddress $address): void
    {
        static::query()
            ->where('customer_id', $customer->id)
            ->where('is_default', true)
            ->where('id', '!=', $address->id)
            ->update(['is_default' => false]);

        $address->forceFill(['is_default' => true])->save();
    }

    /**
     * Build a deduplication hash for the core address fields.
     */
    public function addressHash(): string
    {
        $parts = [
            mb_strtolower(trim($this->canonical_address ?? '')),
            mb_strtolower(trim($this->barangay ?? '')),
            mb_strtolower(trim($this->city_municipality ?? '')),
            mb_strtolower(trim($this->province ?? '')),
            mb_strtolower(trim($this->region ?? '')),
        ];

        return md5(implode('|', $parts));
    }
}
