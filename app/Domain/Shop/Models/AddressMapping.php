<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AddressMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'country',
        'region',
        'province',
        'city_municipality',
        'barangay',
        'island_group',
        'business_region',
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
