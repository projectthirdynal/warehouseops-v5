<?php

declare(strict_types=1);

namespace App\Domain\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'variant_name',
        'selling_price',
        'cost_price',
        'weight_grams',
        'is_active',
    ];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'cost_price'    => 'decimal:2',
        'weight_grams'  => 'integer',
        'is_active'     => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stock(): HasOne
    {
        return $this->hasOne(ProductStock::class, 'variant_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'variant_id');
    }

    /** Resolve price: variant override or fall back to product. */
    public function getEffectivePriceAttribute(): float
    {
        return (float) ($this->selling_price ?? $this->product->selling_price);
    }

    public function getEffectiveCostAttribute(): float
    {
        return (float) ($this->cost_price ?? $this->product->cost_price);
    }

    public function getEffectiveWeightAttribute(): int
    {
        return $this->weight_grams ?? $this->product->weight_grams;
    }
}
