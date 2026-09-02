<?php

namespace App\Models;

use Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionQueue extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'rule_id',
        'status',
        'assigned_agent_id',
        'score_snapshot',
        'attempt_count',
        'processed_at',
    ];

    protected $casts = [
        'score_snapshot' => 'array',
        'attempt_count' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DistributionRule::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending')->orderBy('created_at');
    }

    public function markAsAssigned(int $agentId, ?array $scoreSnapshot = null): void
    {
        $this->update([
            'status' => 'assigned',
            'assigned_agent_id' => $agentId,
            'score_snapshot' => $scoreSnapshot,
            'processed_at' => now(),
        ]);
    }

    public function incrementAttempt(): void
    {
        $this->increment('attempt_count');
    }
}
