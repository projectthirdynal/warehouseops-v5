<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Product\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRule extends Model
{
    protected $fillable = [
        'product_id',
        'rate_type',
        'rate_value',
        'min_sale_amount',
        'is_active',
    ];

    protected $casts = [
        'rate_value'       => 'decimal:2',
        'min_sale_amount'  => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Find the applicable rule for a product (product-specific first, then default).
     */
    public static function forProduct(?int $productId): ?self
    {
        if ($productId) {
            $rule = static::where('product_id', $productId)->where('is_active', true)->first();
            if ($rule) {
                return $rule;
            }
        }

        return static::whereNull('product_id')->where('is_active', true)->first();
    }

    /**
     * Calculate commission for a given sale amount.
     */
    public function calculate(float $saleAmount): float
    {
        if ($this->min_sale_amount && $saleAmount < $this->min_sale_amount) {
            return 0;
        }

        return match ($this->rate_type) {
            'PERCENTAGE' => round($saleAmount * ($this->rate_value / 100), 2),
            'FIXED'      => (float) $this->rate_value,
            default      => 0,
        };
    }
}
