<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CodSettlement extends Model
{
    protected $fillable = [
        'courier_code',
        'reference_number',
        'period_start',
        'period_end',
        'total_cod_collected',
        'expected_cod',
        'courier_fee',
        'net_amount',
        'variance',
        'order_count',
        'matched_count',
        'unmatched_count',
        'status',
        'received_at',
        'reconciled_at',
        'reconciled_by',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_cod_collected' => 'decimal:2',
        'expected_cod' => 'decimal:2',
        'courier_fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'variance' => 'decimal:2',
        'received_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    // Relationships

    public function reconciliationItems(): HasMany
    {
        return $this->hasMany(CodReconciliationItem::class, 'cod_settlement_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeReceived($query)
    {
        return $query->where('status', 'RECEIVED');
    }

    public function scopeReconciled($query)
    {
        return $query->where('status', 'RECONCILED');
    }

    public function scopeByCourier($query, string $code)
    {
        return $query->where('courier_code', $code);
    }

    // Helpers

    public function isReconcilable(): bool
    {
        return $this->status === 'RECEIVED';
    }

    public function isReconciled(): bool
    {
        return $this->status === 'RECONCILED';
    }
}
