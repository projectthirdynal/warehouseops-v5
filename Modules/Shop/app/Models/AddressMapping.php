<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class AddressMapping extends Model
{
    protected $fillable = [
        'country',
        'region',
        'province',
        'city_municipality',
        'barangay',
        'island_group',
        'courier_zone',
        'postal_code',
        'aliases',
        'metadata',
    ];

    protected $casts = [
        'aliases' => 'array',
        'metadata' => 'array',
    ];
}
