<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsSequenceEnrollment extends Model
{
    protected $fillable = [
        'sequence_id',
        'waybill_id',
        'lead_id',
        'phone',
        'current_step',
        'status',
        'next_step_at',
        'completed_at',
    ];

    protected $casts = [
        'next_step_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(SmsSequence::class, 'sequence_id');
    }

    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function advanceStep(): void
    {
        $nextStep = $this->sequence->steps()
            ->where('step_order', '>', $this->current_step)
            ->where('is_active', true)
            ->first();

        if ($nextStep) {
            $this->update([
                'current_step' => $nextStep->step_order,
                'next_step_at' => now()->addMinutes($nextStep->getDelayInMinutes()),
            ]);
        } else {
            $this->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }
}
