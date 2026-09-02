<?php

declare(strict_types=1);

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Warehouse;

class ProductStock extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'variant_id',
        'warehouse_id',
        'location_id',
        'current_stock',
        'reserved_stock',
        'reorder_point',
        'last_restock_at',
        'last_movement_at',
    ];

    protected $appends = ['available_stock', 'is_low_stock'];

    protected $casts = [
        'current_stock' => 'integer',
        'reserved_stock' => 'integer',
        'reorder_point' => 'integer',
        'last_restock_at' => 'datetime',
        'last_movement_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function getAvailableStockAttribute(): int
    {
        return max(0, $this->current_stock - $this->reserved_stock);
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->available_stock <= $this->reorder_point;
    }
}
