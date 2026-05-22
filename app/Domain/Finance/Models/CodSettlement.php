<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class CodSettlement extends Model
{
    protected $fillable = [
        'courier_code',
        'reference_number',
        'period_start',
        'period_end',
        'total_cod_collected',
        'courier_fee',
        'net_amount',
        'order_count',
        'status',
        'received_at',
        'reconciled_at',
        'notes',
    ];

    protected $casts = [
        'period_start'       => 'date',
        'period_end'         => 'date',
        'total_cod_collected' => 'decimal:2',
        'courier_fee'        => 'decimal:2',
        'net_amount'         => 'decimal:2',
        'received_at'        => 'datetime',
        'reconciled_at'      => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeByCourier($query, string $code)
    {
        return $query->where('courier_code', $code);
    }
}
