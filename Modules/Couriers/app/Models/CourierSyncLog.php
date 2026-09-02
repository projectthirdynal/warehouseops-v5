<?php

declare(strict_types=1);

namespace Modules\Couriers\Models;

use Illuminate\Database\Eloquent\Model;

class CourierSyncLog extends Model
{
    protected $fillable = [
        'run_id',
        'courier_code',
        'trigger',
        'waybills_checked',
        'waybills_updated',
        'waybills_unchanged',
        'errors_count',
        'errors',
        'per_courier',
        'duration_ms',
        'status',
    ];

    protected $casts = [
        'errors' => 'array',
        'per_courier' => 'array',
        'waybills_checked' => 'integer',
        'waybills_updated' => 'integer',
        'waybills_unchanged' => 'integer',
        'errors_count' => 'integer',
        'duration_ms' => 'integer',
    ];
}
