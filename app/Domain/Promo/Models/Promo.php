<?php

declare(strict_types=1);

namespace App\Domain\Promo\Models;

use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use App\Domain\Promo\Enums\PromoType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promo extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'promo_code',
        'name',
        'description',
        'type',
        'product_id',
        'variant_id',
        'trigger_quantity',
        'free_quantity',
        'free_product_id',
        'free_variant_id',
        'free_item_name',
        'discount_percentage',
        'is_active',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected $casts = [
        'type' => PromoType::class,
        'trigger_quantity' => 'integer',
        'free_quantity' => 'integer',
        'discount_percentage' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function freeProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'free_product_id');
    }

    public function freeVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'free_variant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }

    public function scopeForProduct($query, ?int $productId)
    {
        if ($productId === null) {
            return $query->whereNull('product_id');
        }

        return $query->where(function ($q) use ($productId) {
            $q->whereNull('product_id')->orWhere('product_id', $productId);
        });
    }

    // Helpers

    /**
     * Check if this promo is currently valid (within date range and active).
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Generate a human-readable summary of the promo.
     */
    public function getSummaryAttribute(): string
    {
        return match ($this->type) {
            PromoType::FREEBIE => "Free {$this->free_item_name}",
            PromoType::BUNDLE => "Buy {$this->trigger_quantity} Take {$this->free_quantity}",
            PromoType::DISCOUNT => "{$this->discount_percentage}% off",
        };
    }
}
