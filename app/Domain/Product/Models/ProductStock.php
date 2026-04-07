<?php

declare(strict_types=1);

namespace App\Domain\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStock extends Model
{
    protected $fillable = [
        'product_id',
        'variant_id',
        'current_stock',
        'reserved_stock',
        'reorder_point',
        'last_restock_at',
    ];

    protected $casts = [
        'current_stock'  => 'integer',
        'reserved_stock' => 'integer',
        'reorder_point'  => 'integer',
        'last_restock_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function getAvailableStockAttribute(): int
    {
        return $this->current_stock - $this->reserved_stock;
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->available_stock <= $this->reorder_point;
    }
}
