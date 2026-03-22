<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsSequenceStep extends Model
{
    protected $fillable = [
        'sequence_id',
        'step_order',
        'message',
        'delay_minutes',
        'delay_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(SmsSequence::class, 'sequence_id');
    }

    /**
     * Get delay in minutes regardless of delay_type
     */
    public function getDelayInMinutes(): int
    {
        return match ($this->delay_type) {
            'hours' => $this->delay_minutes * 60,
            'days' => $this->delay_minutes * 1440,
            default => $this->delay_minutes,
        };
    }
}
