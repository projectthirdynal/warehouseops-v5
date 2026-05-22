<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\ShopPageProductMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductMessageParser
{
    /**
     * @return Collection<int, array{
     *     product: Product,
     *     variant: ProductVariant|null,
     *     sku: string|null,
     *     product_name: string,
     *     quantity: int,
     *     unit_price: float,
     *     line_total: float,
     *     matched_text: string,
     *     confidence: float
     * }>
     */
    public function parse(string $message, ?FacebookPage $facebookPage = null): Collection
    {
        $message = trim($message);

        if ($message === '') {
            return collect();
        }

        $normalizedMessage = $this->normalize($message);
        $tokens = $this->candidateTokens($message);

        if ($tokens === []) {
            return collect();
        }

        $matches = collect();

        $this->variantSkuMatches($tokens, $normalizedMessage)->each(function (ProductVariant $variant) use ($message, $matches) {
            if (! $variant->product || ! $variant->product->is_active) {
                return;
            }

            $matches->push($this->lineFromVariant($variant, $message, 0.98));
        });

        $this->productSkuMatches($tokens)->each(function (Product $product) use ($message, $matches) {
            $matches->push($this->lineFromProduct($product, $message, 0.95));
        });

        $this->nameMatches($tokens, $normalizedMessage)->each(function (array $match) use ($message, $matches) {
            /** @var Product $product */
            $product = $match['product'];
            /** @var ProductVariant|null $variant */
            $variant = $match['variant'];

            $matches->push($variant
                ? $this->lineFromVariant($variant, $message, $match['confidence'])
                : $this->lineFromProduct($product, $message, $match['confidence'])
            );
        });

        $parsed = $matches
            ->unique(fn (array $line) => ($line['product']->id ?? 'p') . ':' . ($line['variant']?->id ?? 'base'))
            ->sortByDesc('confidence')
            ->values();

        if ($parsed->isEmpty() && $facebookPage !== null) {
            $pageLine = $this->lineFromPageMapping($facebookPage, $message);

            return $pageLine === null ? collect() : collect([$pageLine]);
        }

        return $parsed;
    }

    /**
     * @param array<int, string> $tokens
     * @return Collection<int, ProductVariant>
     */
    private function variantSkuMatches(array $tokens, string $normalizedMessage): Collection
    {
        return ProductVariant::query()
            ->with('product:id,sku,name,selling_price,is_active')
            ->where('is_active', true)
            ->whereIn(DB::raw('LOWER(sku)'), $tokens)
            ->get()
            ->filter(fn (ProductVariant $variant) => str_contains($normalizedMessage, $this->normalize((string) $variant->sku)))
            ->values();
    }

    /**
     * @param array<int, string> $tokens
     * @return Collection<int, Product>
     */
    private function productSkuMatches(array $tokens): Collection
    {
        return Product::query()
            ->active()
            ->whereIn(DB::raw('LOWER(sku)'), $tokens)
            ->get(['id', 'sku', 'name', 'selling_price', 'is_active']);
    }

    /**
     * @param array<int, string> $tokens
     * @return Collection<int, array{product: Product, variant: ProductVariant|null, confidence: float}>
     */
    private function nameMatches(array $tokens, string $normalizedMessage): Collection
    {
        $searchTokens = array_values(array_filter($tokens, fn (string $token) => mb_strlen($token) >= 4));

        if ($searchTokens === []) {
            return collect();
        }

        $products = Product::query()
            ->active()
            ->with('variants:id,product_id,sku,variant_name,selling_price,is_active')
            ->where(function ($query) use ($searchTokens) {
                foreach (array_slice($searchTokens, 0, 8) as $token) {
                    $query->orWhereRaw('LOWER(name) LIKE ?', ["%{$token}%"])
                        ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$token}%"])
                        ->orWhereHas('variants', function ($variantQuery) use ($token) {
                            $variantQuery->where('is_active', true)
                                ->where(function ($nested) use ($token) {
                                    $nested->whereRaw('LOWER(variant_name) LIKE ?', ["%{$token}%"])
                                        ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$token}%"]);
                                });
                        });
                }
            })
            ->limit(50)
            ->get();

        return $products->flatMap(function (Product $product) use ($normalizedMessage) {
            $matches = collect();
            $productName = $this->normalize($product->name);

            if ($productName !== '' && str_contains($normalizedMessage, $productName)) {
                $matches->push([
                    'product' => $product,
                    'variant' => null,
                    'confidence' => 0.88,
                ]);
            }

            foreach ($product->variants->where('is_active', true) as $variant) {
                $variantSku = $this->normalize((string) $variant->sku);
                $variantName = $this->normalize((string) $variant->variant_name);

                if (($variantSku !== '' && str_contains($normalizedMessage, $variantSku))
                    || ($variantName !== '' && str_contains($normalizedMessage, $variantName))) {
                    $matches->push([
                        'product' => $product,
                        'variant' => $variant,
                        'confidence' => $variantSku !== '' && str_contains($normalizedMessage, $variantSku) ? 0.93 : 0.82,
                    ]);
                }
            }

            return $matches;
        })->values();
    }

    private function lineFromVariant(ProductVariant $variant, string $message, float $confidence): array
    {
        $product = $variant->product;
        $unitPrice = (float) ($variant->selling_price ?? $product->selling_price ?? 0);
        $quantity = $this->quantityNear($message, [(string) $variant->sku, (string) $variant->variant_name, (string) $product->name]);

        return [
            'product' => $product,
            'variant' => $variant,
            'sku' => $variant->sku ?? $product->sku,
            'product_name' => "{$product->name} - {$variant->variant_name}",
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
            'matched_text' => $variant->sku ?? $variant->variant_name,
            'confidence' => $confidence,
        ];
    }

    private function lineFromProduct(Product $product, string $message, float $confidence): array
    {
        $unitPrice = (float) ($product->selling_price ?? 0);
        $quantity = $this->quantityNear($message, [(string) $product->sku, (string) $product->name]);

        return [
            'product' => $product,
            'variant' => null,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
            'matched_text' => $product->sku ?? $product->name,
            'confidence' => $confidence,
        ];
    }

    private function lineFromPageMapping(FacebookPage $facebookPage, string $message): ?array
    {
        $mapping = ShopPageProductMapping::query()
            ->with(['product:id,sku,name,selling_price,is_active', 'variant:id,product_id,sku,variant_name,selling_price,is_active'])
            ->where('normalized_page_name', ShopPageProductMapping::normalizePageName($facebookPage->page_name))
            ->where('is_active', true)
            ->first();

        if (! $mapping?->product || ! $mapping->product->is_active) {
            return null;
        }

        if ($mapping->variant && $mapping->variant->is_active) {
            $line = $this->lineFromVariant($mapping->variant, $message, 0.7);
        } else {
            $line = $this->lineFromProduct($mapping->product, $message, 0.68);
        }

        $line['matched_text'] = $mapping->page_name;

        return $line;
    }

    /**
     * @param array<int, string> $needles
     */
    private function quantityNear(string $message, array $needles): int
    {
        foreach ($needles as $needle) {
            $needle = trim($needle);

            if ($needle === '') {
                continue;
            }

            $quoted = preg_quote($needle, '/');

            if (preg_match('/\b' . $quoted . '\b\s*(?:x|×|\*)\s*(\d{1,3})\b/i', $message, $match) === 1) {
                return max(1, min(999, (int) $match[1]));
            }

            if (preg_match('/\b(\d{1,3})\s*(?:x|×|\*|pcs?|pieces?|sets?)?\s+\b' . $quoted . '\b/i', $message, $match) === 1) {
                return max(1, min(999, (int) $match[1]));
            }
        }

        if (preg_match('/\b(?:qty|quantity|order)\s*[:=-]?\s*(\d{1,3})\b/i', $message, $match) === 1) {
            return max(1, min(999, (int) $match[1]));
        }

        return 1;
    }

    /**
     * @return array<int, string>
     */
    private function candidateTokens(string $message): array
    {
        preg_match_all('/[a-zA-Z0-9][a-zA-Z0-9._-]{1,}/', $message, $matches);

        $ignored = [
            'address',
            'barangay',
            'blk',
            'block',
            'city',
            'contact',
            'delivery',
            'lot',
            'mobile',
            'number',
            'order',
            'phone',
            'product',
            'province',
            'qty',
            'quantity',
            'street',
        ];

        return collect($matches[0] ?? [])
            ->map(fn (string $token) => Str::lower(trim($token, " \t\n\r\0\x0B.,:;()[]{}")))
            ->filter(fn (string $token) => $token !== '' && ! in_array($token, $ignored, true))
            ->reject(fn (string $token) => preg_match('/^09\d{9}$/', $token) === 1)
            ->unique()
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        $value = Str::lower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
