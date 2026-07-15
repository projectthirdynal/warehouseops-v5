<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingRate extends Model
{
    protected $fillable = [
        'courier_code',
        'courier_zone',
        'base_fee',
        'per_kg_fee',
        'weight_threshold_kg',
        'cod_fee',
        'is_active',
    ];

    protected $casts = [
        'base_fee' => 'decimal:2',
        'per_kg_fee' => 'decimal:2',
        'weight_threshold_kg' => 'decimal:2',
        'cod_fee' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
