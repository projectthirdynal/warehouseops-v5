<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaybillTrackingHistory extends Model
{
    protected $table = 'waybill_tracking_history';

    protected $fillable = [
        'waybill_id',
        'status',
        'previous_status',
        'reason',
        'location',
        'raw_data',
        'tracked_at',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'tracked_at' => 'datetime',
    ];

    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class);
    }
}
