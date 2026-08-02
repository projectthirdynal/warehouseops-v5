<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use HasFactory, SoftDeletes;

    const TYPE_BILLING = 'billing';

    const TYPE_SHIPPING = 'shipping';

    const TYPE_BRANCH = 'branch';

    const TYPE_OTHER = 'other';

    protected $fillable = [
        'third_party_id',
        'type',
        'label',
        'is_default',
        'address_line1',
        'address_line2',
        'barangay',
        'city',
        'state_province',
        'postal_code',
        'country',
        'contact_name',
        'contact_phone',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function getOneLineAttribute(): string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->barangay,
            $this->city,
            $this->state_province,
            $this->postal_code,
        ]);

        return implode(', ', $parts);
    }
}
