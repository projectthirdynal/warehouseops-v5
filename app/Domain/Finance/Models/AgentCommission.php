<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCommission extends Model
{
    protected $fillable = [
        'agent_id',
        'order_id',
        'product_id',
        'lead_id',
        'waybill_id',
        'sale_amount',
        'commission_rate',
        'commission_amount',
        'status',
        'earned_at',
        'approved_at',
        'paid_at',
        'cancelled_at',
        'notes',
    ];

    protected $casts = [
        'sale_amount'       => 'decimal:2',
        'commission_rate'   => 'decimal:4',
        'commission_amount' => 'decimal:2',
        'earned_at'         => 'datetime',
        'approved_at'       => 'datetime',
        'paid_at'           => 'datetime',
        'cancelled_at'      => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'APPROVED');
    }

    public function scopeByAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }
}
