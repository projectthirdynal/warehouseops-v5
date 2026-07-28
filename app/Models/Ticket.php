<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket_number',
        'subject',
        'description',
        'status',
        'priority',
        'category',
        'created_by',
        'assigned_to',
        'related_waybill',
        'related_lead',
        'due_at',
        'resolved_at',
    ];

    protected $casts = [
        'related_lead' => 'integer',
        'due_at'        => 'datetime',
        'resolved_at'   => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->orderBy('created_at');
    }

    public static function generateTicketNumber(): string
    {
        $date = now()->format('ymd');
        $count = self::withTrashed()->whereDate('created_at', today())->count() + 1;

        return "TK-{$date}-" . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * SLA target hours per priority level.
     */
    public static function slaHoursForPriority(string $priority): int
    {
        return match ($priority) {
            'urgent' => 4,
            'high'   => 8,
            'medium' => 24,
            'low'    => 48,
            default  => 24,
        };
    }

    public static function calculateDueAt(string $priority, ?\Carbon\Carbon $createdAt = null): \Carbon\Carbon
    {
        $hours = self::slaHoursForPriority($priority);
        $base = $createdAt ?? now();

        return $base->copy()->addHours($hours);
    }

    public function isOverdue(): bool
    {
        if (!$this->due_at || in_array($this->status, ['resolved', 'closed'])) {
            return false;
        }

        return $this->due_at->isPast();
    }

    public function isBreached(): bool
    {
        if (!$this->due_at) {
            return false;
        }

        if (in_array($this->status, ['resolved', 'closed'])) {
            return $this->resolved_at && $this->resolved_at->gt($this->due_at);
        }

        return $this->due_at->isPast();
    }

    /**
     * Returns: 'on_track' | 'warning' | 'overdue' | 'breached' | 'met' | 'none'
     */
    public function slaStatus(): string
    {
        if (!$this->due_at) {
            return 'none';
        }

        if (in_array($this->status, ['resolved', 'closed'])) {
            return $this->resolved_at && $this->resolved_at->gt($this->due_at) ? 'breached' : 'met';
        }

        if ($this->due_at->isPast()) {
            return 'overdue';
        }

        $hoursLeft = now()->diffInHours($this->due_at, false);
        if ($hoursLeft <= 1) {
            return 'warning';
        }

        return 'on_track';
    }

    public function timeRemaining(): ?array
    {
        if (!$this->due_at) {
            return null;
        }

        if (in_array($this->status, ['resolved', 'closed'])) {
            return null;
        }

        $diff = now()->diff($this->due_at);

        if ($this->due_at->isPast()) {
            return [
                'overdue' => true,
                'hours'   => abs(now()->diffInHours($this->due_at)),
                'human'   => now()->diffForHumans($this->due_at, true) . ' overdue',
            ];
        }

        return [
            'overdue' => false,
            'hours'   => $diff->h + ($diff->days * 24),
            'human'   => $this->due_at->diffForHumans(now(), true) . ' remaining',
        ];
    }
}
