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
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
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
