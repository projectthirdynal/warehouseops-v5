<?php

declare(strict_types=1);

namespace App\Domain\Product\Models;

use App\Domain\Shop\Models\ShopPageProductMapping;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sku',
        'barcode',
        'qr_code',
        'name',
        'catalog_remarks',
        'brand',
        'category',
        'uom_id',
        'selling_price',
        'cost_price',
        'min_stock_level',
        'max_stock_level',
        'expiry_tracking',
        'weight_grams',
        'description',
        'image_url',
        'is_active',
        'requires_qa',
    ];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'cost_price'    => 'decimal:2',
        'min_stock_level' => 'integer',
        'max_stock_level' => 'integer',
        'expiry_tracking' => 'boolean',
        'weight_grams'  => 'integer',
        'is_active'     => 'boolean',
        'requires_qa'   => 'boolean',
    ];

    // Relationships

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function activeVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->where('is_active', true);
    }

    public function stock(): HasOne
    {
        return $this->hasOne(ProductStock::class)->whereNull('variant_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function pageMappings(): HasMany
    {
        return $this->hasMany(ShopPageProductMapping::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'ILIKE', "%{$term}%")
              ->orWhere('sku', 'ILIKE', "%{$term}%")
              ->orWhere('brand', 'ILIKE', "%{$term}%");
        });
    }

    // Accessors

    public function getMarginAttribute(): float
    {
        if ($this->selling_price <= 0) {
            return 0;
        }
        return round(($this->selling_price - $this->cost_price) / $this->selling_price * 100, 1);
    }

    public function getAvailableStockAttribute(): int
    {
        return ($this->stock?->current_stock ?? 0) - ($this->stock?->reserved_stock ?? 0);
    }

    public function getIsLowStockAttribute(): bool
    {
        if (!$this->stock) {
            return false;
        }
        return ($this->stock->current_stock - $this->stock->reserved_stock) <= $this->stock->reorder_point;
    }
}
