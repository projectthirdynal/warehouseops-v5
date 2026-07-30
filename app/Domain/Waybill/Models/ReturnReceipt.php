<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnReceipt extends Model
{
    protected $fillable = [
        'waybill_id',
        'scanned_by',
        'scanned_at',
        'condition',
        'notes',
        'inventory_updated',
        'inventory_movement_id',
        'finance_notified',
        'processed_at',
    ];

    protected $casts = [
        'scanned_at'      => 'datetime',
        'processed_at'    => 'datetime',
        'inventory_updated'  => 'boolean',
        'finance_notified'   => 'boolean',
    ];

    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class);
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
