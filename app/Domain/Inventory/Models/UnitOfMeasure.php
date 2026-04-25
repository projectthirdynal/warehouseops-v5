<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    protected $fillable = ['name', 'abbreviation', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
