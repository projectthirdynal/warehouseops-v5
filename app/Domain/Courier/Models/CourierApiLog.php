<?php

declare(strict_types=1);

namespace App\Domain\Courier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierApiLog extends Model
{
    protected $fillable = [
        'courier_provider_id',
        'courier_code',
        'action',
        'direction',
        'endpoint',
        'request_data',
        'response_data',
        'http_status',
        'is_success',
        'error_message',
        'response_time_ms',
        'waybill_id',
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'is_success' => 'boolean',
        'response_time_ms' => 'decimal:2',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class, 'courier_provider_id');
    }

    public function waybill(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Waybill::class);
    }
}
