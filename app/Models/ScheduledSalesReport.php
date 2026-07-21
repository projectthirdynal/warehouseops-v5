<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduledSalesReport extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'frequency',
        'send_at',
        'day_of_week',
        'day_of_month',
        'format',
        'lookback_days',
        'recipients',
        'is_active',
        'last_run_at',
        'next_run_at',
    ];

    protected $casts = [
        'send_at' => 'datetime:H:i',
        'recipients' => 'array',
        'is_active' => 'boolean',
        'day_of_month' => 'integer',
        'lookback_days' => 'integer',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
