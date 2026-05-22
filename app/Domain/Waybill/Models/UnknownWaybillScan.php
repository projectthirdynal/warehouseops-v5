<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnknownWaybillScan extends Model
{
    protected $fillable = [
        'waybill_no',
        'scanned_by',
        'scanned_at',
        'scan_session_id',
        'resolution_status',
        'resolved_to_waybill_id',
        'notes',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'scanned_at'  => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function resolvedToWaybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class, 'resolved_to_waybill_id');
    }

    public function scopePending($query)
    {
        return $query->where('resolution_status', 'PENDING');
    }
}
