<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShopPageProductMapping extends Model
{
    protected $fillable = [
        'page_name',
        'normalized_page_name',
        'brand_name',
        'remarks',
        'product_id',
        'variant_id',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public static function normalizePageName(string $pageName): string
    {
        $pageName = Str::lower($pageName);
        $pageName = preg_replace('/[^a-z0-9]+/', ' ', $pageName) ?? $pageName;

        return trim(preg_replace('/\s+/', ' ', $pageName) ?? $pageName);
    }
}
