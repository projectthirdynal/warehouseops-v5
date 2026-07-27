<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected $casts = [
        'related_lead' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public static function generateTicketNumber(): string
    {
        $date = now()->format('ymd');
        $count = self::withTrashed()->whereDate('created_at', today())->count() + 1;

        return "TK-{$date}-" . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}
