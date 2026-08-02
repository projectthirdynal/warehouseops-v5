<?php

declare(strict_types=1);

namespace App\Domain\Product\Models;

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
        'name',
        'brand',
        'category',
        'barcode',
        'qr_code',
        'selling_price',
        'cost_price',
        'weight_grams',
        'description',
        'image_url',
        'is_active',
        'requires_qa',
    ];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'weight_grams' => 'integer',
        'is_active' => 'boolean',
        'requires_qa' => 'boolean',
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

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])
                ->orWhereRaw('LOWER(sku) LIKE ?', ['%'.mb_strtolower($term).'%'])
                ->orWhereRaw('LOWER(brand) LIKE ?', ['%'.mb_strtolower($term).'%']);
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
        if (! $this->stock) {
            return 0;
        }

        return $this->stock->current_stock - $this->stock->reserved_stock;
    }

    public function getIsLowStockAttribute(): bool
    {
        if (! $this->stock) {
            return false;
        }

        return ($this->stock->current_stock - $this->stock->reserved_stock) <= $this->stock->reorder_point;
    }
}
