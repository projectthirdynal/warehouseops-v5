<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsSequence extends Model
{
    protected $fillable = [
        'name',
        'description',
        'trigger_event',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SmsSequenceStep::class, 'sequence_id')->orderBy('step_order');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SmsSequenceEnrollment::class, 'sequence_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SmsLog::class, 'sequence_id');
    }
}
