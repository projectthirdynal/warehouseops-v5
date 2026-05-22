<?php

declare(strict_types=1);

namespace App\Domain\Courier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourierProvider extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
        'config',
        'api_endpoint',
        'webhook_secret',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
    ];

    public function apiLogs(): HasMany
    {
        return $this->hasMany(CourierApiLog::class);
    }

    public function waybills(): HasMany
    {
        return $this->hasMany(\App\Models\Waybill::class, 'courier_provider', 'code');
    }
}
