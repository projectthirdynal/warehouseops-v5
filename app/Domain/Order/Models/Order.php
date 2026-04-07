<?php

declare(strict_types=1);

namespace App\Domain\Order\Models;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number',
        'lead_id',
        'customer_id',
        'product_id',
        'variant_id',
        'assigned_agent_id',
        'status',
        'courier_code',
        'waybill_id',
        'quantity',
        'unit_price',
        'total_amount',
        'cod_amount',
        'shipping_cost',
        'receiver_name',
        'receiver_phone',
        'receiver_address',
        'city',
        'state',
        'barangay',
        'postal_code',
        'notes',
        'rejection_reason',
        'confirmed_at',
        'dispatched_at',
        'delivered_at',
        'returned_at',
    ];

    protected $casts = [
        'status'        => OrderStatus::class,
        'unit_price'    => 'decimal:2',
        'total_amount'  => 'decimal:2',
        'cod_amount'    => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'confirmed_at'  => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at'  => 'datetime',
        'returned_at'   => 'datetime',
    ];

    // Relationships

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class);
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', OrderStatus::PENDING);
    }

    public function scopeReadyForDispatch($query)
    {
        return $query->whereIn('status', [OrderStatus::QA_APPROVED, OrderStatus::CONFIRMED]);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            OrderStatus::DELIVERED,
            OrderStatus::RETURNED,
            OrderStatus::CANCELLED,
            OrderStatus::QA_REJECTED,
        ]);
    }

    // Helpers

    public static function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $count = static::whereDate('created_at', today())->count() + 1;

        return sprintf('ORD-%s-%04d', $date, $count);
    }
}
