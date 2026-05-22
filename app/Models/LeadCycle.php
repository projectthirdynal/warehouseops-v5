<?php

namespace App\Models;

use App\Domain\Lead\Models\Lead;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadCycle extends Model
{
    use HasFactory;
    protected $fillable = [
        'lead_id',
        'cycle_number',
        'assigned_agent_id',
        'status',
        'outcome',
        'opened_at',
        'closed_at',
        'last_call_at',
        'call_count',
        'callback_at',
        'callback_notes',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_call_at' => 'datetime',
        'callback_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function recordCall(): void
    {
        $this->increment('call_count');
        $this->update(['last_call_at' => now()]);
    }
}
