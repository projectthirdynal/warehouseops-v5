<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use App\Domain\Shop\Models\ShopPageProductMapping;

class ProductPageMappingService
{
    /**
     * @param array<int, string> $pageNames
     */
    public function sync(Product $product, array $pageNames, ?string $brandName = null, ?string $source = null): void
    {
        $pageNames = collect($pageNames)
            ->map(fn (string $pageName) => trim($pageName))
            ->filter()
            ->unique(fn (string $pageName) => ShopPageProductMapping::normalizePageName($pageName))
            ->values();

        $activeNormalizedNames = [];
        $remarks = $this->remarks($product);

        foreach ($pageNames as $pageName) {
            $normalizedPageName = ShopPageProductMapping::normalizePageName($pageName);
            $activeNormalizedNames[] = $normalizedPageName;

            ShopPageProductMapping::query()->updateOrCreate(
                ['normalized_page_name' => $normalizedPageName],
                [
                    'page_name' => $pageName,
                    'brand_name' => $brandName !== null && trim($brandName) !== '' ? trim($brandName) : $product->brand,
                    'remarks' => $remarks,
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'is_active' => $product->is_active,
                    'metadata' => array_filter([
                        'source' => $source,
                        'catalog_sku' => $product->sku,
                    ]),
                ]
            );
        }

        $query = $product->pageMappings();

        if ($activeNormalizedNames !== []) {
            $query->whereNotIn('normalized_page_name', $activeNormalizedNames);
        }

        $query->update(['is_active' => false]);
    }

    public function remarks(Product|ProductVariant $item): string
    {
        if ($item instanceof ProductVariant) {
            return trim((string) ($item->variant_name ?: $item->sku));
        }

        return trim((string) ($item->catalog_remarks ?: $item->name ?: $item->sku));
    }
}
