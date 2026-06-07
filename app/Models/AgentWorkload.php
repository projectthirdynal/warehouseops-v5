<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentWorkload extends Model
{
    use HasFactory;

    protected $primaryKey = 'agent_id';
    public $incrementing = false;

    protected $fillable = [
        'agent_id',
        'active_leads_count',
        'today_assigned_count',
        'today_converted_count',
        'last_assigned_at',
        'next_available_at',
    ];

    protected $casts = [
        'active_leads_count' => 'integer',
        'today_assigned_count' => 'integer',
        'today_converted_count' => 'integer',
        'last_assigned_at' => 'datetime',
        'next_available_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function isAtCapacity(int $maxCycles): bool
    {
        return $this->active_leads_count >= $maxCycles;
    }

    public function isDailyCapReached(int $maxDaily): bool
    {
        // Reset counter if last assignment was before today
        if ($this->last_assigned_at && ! $this->last_assigned_at->isToday()) {
            return false;
        }

        return $this->today_assigned_count >= $maxDaily;
    }

    public function recordAssignment(): void
    {
        $this->active_leads_count++;
        $this->today_assigned_count++;
        $this->last_assigned_at = now();
        $this->save();
    }

    public function recordConversion(): void
    {
        $this->today_converted_count++;
        $this->save();
    }

    public function recordCycleClose(): void
    {
        $this->active_leads_count = max(0, $this->active_leads_count - 1);
        $this->save();
    }
}
