<?php

declare(strict_types=1);

namespace App\Domain\Order\Models;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Domain\Shop\Models\OrderRemark;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number',
        'lead_id',
        'parent_order_id',
        'conversation_id',
        'facebook_page_id',
        'customer_id',
        'product_id',
        'variant_id',
        'assigned_agent_id',
        'encoder_id',
        'status',
        'courier_code',
        'waybill_id',
        'quantity',
        'unit_price',
        'total_amount',
        'cod_amount',
        'shipping_cost',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'receiver_name',
        'receiver_phone',
        'receiver_address',
        'city',
        'state',
        'barangay',
        'postal_code',
        'landmark',
        'nearest_landmark',
        'latitude',
        'longitude',
        'address_mapping_id',
        'source_channel',
        'address_confidence',
        'export_status',
        'notes',
        'remarks',
        'draft_data',
        'rejection_reason',
        'confirmed_at',
        'dispatched_at',
        'delivered_at',
        'returned_at',
        'encoded_at',
        'held_at',
        'hold_reason',
    ];

    protected $casts = [
        'status'        => OrderStatus::class,
        'unit_price'    => 'decimal:2',
        'total_amount'  => 'decimal:2',
        'cod_amount'    => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'address_confidence' => 'decimal:2',
        'confirmed_at'  => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at'  => 'datetime',
        'returned_at'   => 'datetime',
        'encoded_at'    => 'datetime',
        'held_at'        => 'datetime',
        'draft_data'    => 'array',
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

    public function shopItems(): HasMany
    {
        return $this->hasMany(ShopOrderItem::class);
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    public function childOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_order_id');
    }

    public function remarks(): HasMany
    {
        return $this->hasMany(OrderRemark::class);
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
