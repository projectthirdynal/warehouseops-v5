<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Models\SiteSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Product recommendations for order upsells, conversations and dashboards.
 *
 * Three algorithms:
 *  - item_based:    market-basket collaborative filtering — cosine similarity
 *                   between products over shared orders ("bought X also bought Y").
 *  - content_based: category / brand / price proximity scoring.
 *  - hybrid:        65% item_based + 35% content_based (default).
 *
 * Settings live in SiteSetting under "recommendation_*" keys and are managed
 * via getSettings()/updateSettings().
 */
class ProductRecommendationService
{
    private const CACHE_TTL = 300;

    private const HYBRID_ITEM_WEIGHT = 0.65;

    private const HYBRID_CONTENT_WEIGHT = 0.35;

    private const CONTENT_WEIGHTS = [
        'category' => 0.45,
        'brand' => 0.30,
        'price' => 0.25,
    ];

    /** Statuses that represent a realisable purchase signal. */
    private const VALID_STATUSES = [
        OrderStatus::DELIVERED,
        OrderStatus::CONFIRMED,
        OrderStatus::QA_APPROVED,
        OrderStatus::DISPATCHED,
    ];

    // -------------------------------------------------------------------------
    // Public API (ShopController endpoints depend on these signatures)
    // -------------------------------------------------------------------------

    /**
     * Recommend products related to the given seed product ids.
     *
     * @return list<array{id: int, sku: string, name: string, brand: ?string, category: ?string, selling_price: float, image_url: ?string, score: float, frequency: int}>
     */
    public function recommend(array $productIds, int $limit = 5): array
    {
        $productIds = array_values(array_unique(array_filter(array_map(intval(...), $productIds), fn ($id) => $id > 0)));

        if ($productIds === []) {
            return [];
        }

        $limit = $this->clampLimit($limit);
        $algorithm = $this->algorithm();

        return $this->remember('recs:'.md5(implode(',', $productIds).':'.$algorithm.':'.$limit), function () use ($productIds, $algorithm, $limit) {
            return match ($algorithm) {
                'item_based' => $this->itemBasedRecommend($productIds, $limit),
                'content_based' => $this->contentBasedRecommend($productIds, $limit),
                default => $this->hybridRecommend($productIds, $limit),
            };
        });
    }

    /**
     * Personalised recommendations from a customer's purchase history.
     *
     * Recent purchases act as collaborative-filtering seeds while the full
     * history drives content similarity; already-purchased products are
     * excluded. Falls back to popular products when no history exists.
     *
     * @return list<array<string, mixed>>
     */
    public function recommendForCustomer(int $customerId, int $limit = 5): array
    {
        $limit = $this->clampLimit($limit);

        return $this->remember('cust_recs:'.$customerId.':'.$this->algorithm().':'.$limit, function () use ($customerId, $limit) {
            $history = $this->customerHistory($customerId);

            if ($history === []) {
                return $this->popularProducts($limit);
            }

            $owned = array_fill_keys($history, true);

            // Most recent distinct purchases carry the strongest taste signal.
            $seeds = array_slice($history, 0, 5);

            $itemBased = $this->itemBasedRecommend($seeds, $limit * 2);
            $contentBased = $this->contentBasedRecommend($history, $limit * 2);

            $blended = $this->blendComponentRows($itemBased, $contentBased, self::HYBRID_ITEM_WEIGHT, self::HYBRID_CONTENT_WEIGHT);

            $personalised = array_values(array_filter(
                $blended,
                fn (array $row) => ! isset($owned[$row['id']]),
            ));

            if ($personalised === []) {
                return $this->popularProducts($limit);
            }

            foreach ($personalised as &$row) {
                $row['source'] = 'personalised';
            }

            return array_slice($personalised, 0, $limit);
        });
    }

    /**
     * Convenience wrapper for single-product contexts (conversation upsells).
     *
     * @return list<array<string, mixed>>
     */
    public function recommendForProduct(int $productId, ?int $limit = null): array
    {
        $rows = $this->recommend([$productId], $limit ?? $this->resultCount());

        foreach ($rows as &$row) {
            $row['source'] = $this->algorithm();
            $row['seed_product_id'] = $productId;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $totalProducts = Product::where('is_active', true)->count();
        $validStatuses = $this->validStatuses();

        $totalOrders = Order::query()->whereIn('status', $validStatuses)->count();
        $totalOrderItems = ShopOrderItem::count();
        $uniqueProductsOrdered = ShopOrderItem::distinct('product_id')->count('product_id');
        $avgItemsPerOrder = $totalOrders > 0 ? round($totalOrderItems / $totalOrders, 2) : 0;

        [$topPairs, $qualifyingPairs, $pairedProducts] = $this->coOccurrenceInsights(5, $this->minCoOccurrence());

        return [
            'total_products' => $totalProducts,
            'total_orders' => $totalOrders,
            'total_order_items' => $totalOrderItems,
            'unique_products_ordered' => $uniqueProductsOrdered,
            'avg_items_per_order' => $avgItemsPerOrder,
            'top_recommended' => $this->getTopRecommendedProducts(10),
            'top_co_occurring' => $topPairs,
            'qualifying_pairs' => $qualifyingPairs,
            'pair_coverage_percent' => $totalProducts > 0 ? round($pairedProducts / $totalProducts * 100, 1) : 0.0,
            'lookback_days' => $this->lookbackDays(),
            'algorithm' => $this->algorithm(),
            'cache_enabled' => $this->cacheEnabled(),
            'cache_ttl_seconds' => $this->cacheTtlSeconds(),
            'result_count' => $this->resultCount(),
            'coverage' => $totalProducts > 0 ? round($uniqueProductsOrdered / $totalProducts * 100, 1) : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return [
            'algorithm' => $this->algorithm(),
            'cache_enabled' => $this->cacheEnabled(),
            'cache_ttl_seconds' => $this->cacheTtlSeconds(),
            'result_count' => $this->resultCount(),
            'min_co_occurrence' => $this->minCoOccurrence(),
            'lookback_days' => $this->lookbackDays(),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function updateSettings(array $settings): array
    {
        $allowed = ['algorithm', 'cache_enabled', 'cache_ttl_seconds', 'result_count', 'min_co_occurrence', 'lookback_days'];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $settings)) {
                SiteSetting::set('recommendation_'.$key, $settings[$key]);
            }
        }

        $this->clearCache();

        return $this->getSettings();
    }

    public function clearCache(): int
    {
        $versionKey = 'product_recs_cache_version';
        $current = (int) Cache::get($versionKey, 0);
        Cache::put($versionKey, $current + 1, now()->addYear());

        return 1;
    }

    // -------------------------------------------------------------------------
    // Algorithms
    // -------------------------------------------------------------------------

    /**
     * Market-basket item-based recommendations: candidates frequently bought
     * with the seeds, ranked by cosine similarity over shared orders.
     *
     * @param  list<int>  $productIds
     * @return list<array<string, mixed>>
     */
    protected function itemBasedRecommend(array $productIds, int $limit): array
    {
        $orderIds = $this->ordersContaining($productIds);

        if ($orderIds === []) {
            return [];
        }

        $minCoOccurrence = $this->minCoOccurrence();

        $candidates = DB::table('shop_order_items')
            ->whereIn('order_id', $orderIds)
            ->whereNotIn('product_id', $productIds)
            ->selectRaw('product_id, COUNT(DISTINCT order_id) as overlap')
            ->groupBy('product_id')
            ->havingRaw('COUNT(DISTINCT order_id) >= ?', [$minCoOccurrence])
            ->orderByDesc('overlap')
            ->limit($limit * 3)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $candidateIds = $candidates->pluck('product_id')->all();

        $totals = DB::table('shop_order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereIn('i.product_id', $candidateIds)
            ->whereIn('o.status', $this->validStatuses())
            ->where('o.created_at', '>=', $this->since())
            ->selectRaw('i.product_id, COUNT(DISTINCT i.order_id) as total_orders')
            ->groupBy('i.product_id')
            ->pluck('total_orders', 'product_id');

        $targetOrders = max(1, count($orderIds));

        $scored = $candidates
            ->map(function ($candidate) use ($totals, $targetOrders) {
                $candidateOrders = (int) ($totals[$candidate->product_id] ?? 0);

                return [
                    'id' => (int) $candidate->product_id,
                    'frequency' => (int) $candidate->overlap,
                    'score' => $this->cosineFromCounts((int) $candidate->overlap, $targetOrders, $candidateOrders),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        return $this->formatResults($scored);
    }

    /**
     * Content-based recommendations: category / brand / price proximity to
     * the source products, averaged per candidate across all sources.
     *
     * @param  list<int>  $productIds
     * @return list<array<string, mixed>>
     */
    protected function contentBasedRecommend(array $productIds, int $limit): array
    {
        $sourceProducts = Product::query()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get(['id', 'category', 'brand', 'selling_price']);

        if ($sourceProducts->isEmpty()) {
            return [];
        }

        $categories = $sourceProducts->pluck('category')->filter()->unique()->values()->all();
        $brands = $sourceProducts->pluck('brand')->filter()->unique()->values()->all();

        $query = Product::query()
            ->where('is_active', true)
            ->whereNotIn('id', $productIds);

        if ($categories !== [] || $brands !== []) {
            $query->where(function ($q) use ($categories, $brands) {
                if ($categories !== []) {
                    $q->orWhereIn('category', $categories);
                }
                if ($brands !== []) {
                    $q->orWhereIn('brand', $brands);
                }
            });
        } else {
            // Nothing to anchor on except price; take a broad slice of the catalogue.
            $query->orderByDesc('created_at');
        }

        $candidates = $query->limit($limit * 3)->get(['id', 'category', 'brand', 'selling_price']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $sourceAttrs = $sourceProducts
            ->map(fn (Product $p) => $this->attrsRow($p->category, $p->brand, $p->selling_price))
            ->all();

        $scored = $candidates
            ->map(function (Product $candidate) use ($sourceAttrs) {
                $candAttrs = $this->attrsRow($candidate->category, $candidate->brand, $candidate->selling_price);
                $sum = 0.0;

                foreach ($sourceAttrs as $source) {
                    $sum += $this->contentScore($source, $candAttrs);
                }

                return [
                    'id' => (int) $candidate->id,
                    'frequency' => 0,
                    'score' => round($sum / count($sourceAttrs), 4),
                ];
            })
            ->filter(fn (array $row) => $row['score'] > 0.0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        return $this->formatResults($scored);
    }

    /**
     * Hybrid blend: 65% item_based + 35% content_based.
     *
     * @param  list<int>  $productIds
     * @return list<array<string, mixed>>
     */
    protected function hybridRecommend(array $productIds, int $limit): array
    {
        $itemBased = collect($this->itemBasedRecommend($productIds, $limit * 2))->mapWithKeys(fn (array $r) => [$r['id'] => $r]);
        $contentBased = collect($this->contentBasedRecommend($productIds, $limit * 2))->mapWithKeys(fn (array $r) => [$r['id'] => $r]);

        $merged = $this->blendComponentRows(
            $itemBased->all(),
            $contentBased->all(),
            self::HYBRID_ITEM_WEIGHT,
            self::HYBRID_CONTENT_WEIGHT,
        );

        return array_slice($merged, 0, $limit);
    }

    // -------------------------------------------------------------------------
    // Pure scoring helpers (unit-testable, no I/O)
    // -------------------------------------------------------------------------

    /** Cosine similarity from pre-computed set sizes and overlap. */
    public function cosineFromCounts(int $overlap, int $sizeA, int $sizeB): float
    {
        if ($overlap <= 0 || $sizeA <= 0 || $sizeB <= 0) {
            return 0.0;
        }

        return round(min(1.0, $overlap / sqrt($sizeA * $sizeB)), 4);
    }

    /** Cosine similarity between two customer/order id sets. */
    public function cosineFromSets(array $setA, array $setB): float
    {
        $a = array_fill_keys($setA, true);
        $b = array_fill_keys($setB, true);
        $overlap = count(array_intersect_key($a, $b));

        return $this->cosineFromCounts($overlap, count($a), count($b));
    }

    /**
     * Weighted content similarity between two attribute rows. Dimensions the
     * target does not carry are skipped and weights re-normalised, so sparse
     * products still produce fair scores.
     *
     * @param  array{category: ?string, brand: ?string, selling_price: ?float}  $target
     * @param  array{category: ?string, brand: ?string, selling_price: ?float}  $candidate
     */
    public function contentScore(array $target, array $candidate): float
    {
        $parts = [];

        if (! empty($target['category'])) {
            $parts[] = [self::CONTENT_WEIGHTS['category'], $this->sameToken($target['category'], $candidate['category'] ?? null)];
        }

        if (! empty($target['brand'])) {
            $parts[] = [self::CONTENT_WEIGHTS['brand'], $this->sameToken($target['brand'], $candidate['brand'] ?? null)];
        }

        $targetPrice = (float) ($target['selling_price'] ?? 0);
        $candidatePrice = (float) ($candidate['selling_price'] ?? 0);

        if ($targetPrice > 0 && $candidatePrice > 0) {
            $proximity = 1 - min(1.0, abs($targetPrice - $candidatePrice) / max($targetPrice, $candidatePrice));
            $parts[] = [self::CONTENT_WEIGHTS['price'], $proximity];
        }

        if ($parts === []) {
            return 0.0;
        }

        $weightSum = array_sum(array_column($parts, 0));

        return round(array_sum(array_map(fn (array $part) => $part[0] * $part[1], $parts)) / $weightSum, 4);
    }

    private function sameToken(?string $a, ?string $b): float
    {
        return $a !== null && $b !== null && strtolower(trim($a)) === strtolower(trim($b)) ? 1.0 : 0.0;
    }

    /**
     * Blend component result rows into one ranking: weightedScore =
     * itemWeight*item.score + contentWeight*content.score, matched by product id.
     *
     * @param  array<int, array<string, mixed>>  $itemRows
     * @param  array<int, array<string, mixed>>  $contentRows
     * @return list<array<string, mixed>>
     */
    public function blendComponentRows(array $itemRows, array $contentRows, float $itemWeight, float $contentWeight): array
    {
        $merged = [];

        foreach ($itemRows as $row) {
            $id = $row['id'];

            if (! isset($merged[$id])) {
                $merged[$id] = ['score' => 0.0, 'item' => null, 'content' => null];
            }

            $merged[$id]['score'] += $itemWeight * (float) $row['score'];
            $merged[$id]['item'] = $row;
        }

        foreach ($contentRows as $row) {
            $id = $row['id'];

            if (! isset($merged[$id])) {
                $merged[$id] = ['score' => 0.0, 'item' => null, 'content' => null];
            }

            $merged[$id]['score'] += $contentWeight * (float) $row['score'];
            $merged[$id]['content'] = $row;
        }

        $rows = [];

        foreach ($merged as $entry) {
            $base = $entry['item'] ?? $entry['content'];

            if ($base === null) {
                continue;
            }

            $base['score'] = round((float) $entry['score'], 4);
            $base['item_score'] = $entry['item'] !== null ? round((float) $entry['item']['score'], 4) : 0.0;
            $base['content_score'] = $entry['content'] !== null ? round((float) $entry['content']['score'], 4) : 0.0;

            $rows[] = $base;
        }

        usort($rows, fn (array $a, array $b) => [$b['score'], $b['frequency'], $a['id']] <=> [$a['score'], $a['frequency'], $b['id']]);

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Statistics support
    // -------------------------------------------------------------------------

    /**
     * Top co-occurring pairs plus coverage counts within the lookback window.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    protected function coOccurrenceInsights(int $limit, int $minCoOccurrence): array
    {
        $pairs = DB::table('shop_order_items as a')
            ->join('shop_order_items as b', 'a.order_id', '=', 'b.order_id')
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->whereColumn('a.product_id', '<', 'b.product_id')
            ->whereIn('o.status', $this->validStatuses())
            ->where('o.created_at', '>=', $this->since())
            ->selectRaw('a.product_id as pid_a, b.product_id as pid_b, COUNT(DISTINCT a.order_id) as co_occurrence')
            ->groupBy('a.product_id', 'b.product_id')
            ->havingRaw('COUNT(DISTINCT a.order_id) >= ?', [$minCoOccurrence])
            ->orderByDesc('co_occurrence')
            ->get();

        $qualifying = $pairs->count();
        $pairedProducts = $pairs->flatMap(fn ($p) => [(int) $p->pid_a, (int) $p->pid_b])->unique()->count();

        $pairSizes = DB::table('shop_order_items')
            ->whereIn('product_id', $pairs->flatMap(fn ($p) => [(int) $p->pid_a, (int) $p->pid_b])->unique()->values()->all())
            ->selectRaw('product_id, COUNT(DISTINCT order_id) as total_orders')
            ->groupBy('product_id')
            ->pluck('total_orders', 'product_id');

        $allIds = $pairs->flatMap(fn ($p) => [(int) $p->pid_a, (int) $p->pid_b])->unique()->values()->all();
        $products = $allIds === []
            ? collect()
            : Product::whereIn('id', $allIds)->get(['id', 'name'])->keyBy('id');

        $top = $pairs
            ->take($limit)
            ->map(function ($p) use ($products, $pairSizes) {
                $nameA = $products->has($p->pid_a) ? $products[$p->pid_a]->name : "Product #{$p->pid_a}";
                $nameB = $products->has($p->pid_b) ? $products[$p->pid_b]->name : "Product #{$p->pid_b}";

                return [
                    'product_a' => $nameA,
                    'product_b' => $nameB,
                    'product_ids' => [(int) $p->pid_a, (int) $p->pid_b],
                    'co_occurrence' => (int) $p->co_occurrence,
                    'cosine_similarity' => $this->cosineFromCounts(
                        (int) $p->co_occurrence,
                        (int) ($pairSizes[$p->pid_a] ?? 0),
                        (int) ($pairSizes[$p->pid_b] ?? 0),
                    ),
                ];
            })
            ->all();

        return [$top, $qualifying, $pairedProducts];
    }

    /** Bestsellers by distinct purchasing customers within the window. */
    protected function popularProducts(int $limit): array
    {
        $rows = DB::table('shop_order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereIn('o.status', $this->validStatuses())
            ->where('o.created_at', '>=', $this->since())
            ->selectRaw('i.product_id, COUNT(*) as freq')
            ->groupBy('i.product_id')
            ->orderByDesc('freq')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $products = Product::whereIn('id', $rows->pluck('product_id'))
            ->where('is_active', true)
            ->get(['id', 'sku', 'name', 'brand', 'category', 'selling_price', 'image_url'])
            ->keyBy('id');

        $formatted = [];

        foreach ($rows as $row) {
            if (! $products->has($row->product_id)) {
                continue;
            }

            $formatted[] = $this->formatProduct($products[$row->product_id], 0.0, (int) $row->freq) + [
                'source' => 'popular',
                'item_score' => 0.0,
                'content_score' => 0.0,
            ];
        }

        return $formatted;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Customer's purchased product ids, newest first, de-duplicated.
     *
     * @return list<int>
     */
    protected function customerHistory(int $customerId): array
    {
        $history = Order::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $this->validStatuses())
            ->where('created_at', '>=', $this->since())
            ->with('shopItems:order_id,product_id')
            ->orderByDesc('created_at')
            ->get(['id'])
            ->flatMap(fn ($order) => $order->shopItems->pluck('product_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return array_values($history);
    }

    /**
     * Distinct order ids containing any of the seed products, inside the
     * status whitelist and lookback window (capped for runaway baskets).
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function ordersContaining(array $productIds): array
    {
        $cap = 2000;

        $orderIds = DB::table('shop_order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereIn('i.product_id', $productIds)
            ->whereIn('o.status', $this->validStatuses())
            ->where('o.created_at', '>=', $this->since())
            ->distinct()
            ->limit($cap)
            ->pluck('i.order_id');

        return $orderIds->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Attach product attributes to scored rows; drops inactive/missing rows.
     *
     * @param  list<array{id: int, score: float, frequency: int}>  $scored
     * @return list<array<string, mixed>>
     */
    private function formatResults(array $scored): array
    {
        if ($scored === []) {
            return [];
        }

        $productIds = array_column($scored, 'id');

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get(['id', 'sku', 'name', 'brand', 'category', 'selling_price', 'image_url'])
            ->keyBy('id');

        $results = [];

        foreach ($scored as $item) {
            if (! $products->has($item['id'])) {
                continue;
            }

            $results[] = $this->formatProduct($products[$item['id']], (float) $item['score'], (int) $item['frequency']);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatProduct(Product $product, float $score, int $frequency): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'brand' => $product->brand,
            'category' => $product->category,
            'selling_price' => (float) $product->selling_price,
            'image_url' => $product->image_url,
            'score' => round($score, 4),
            'frequency' => $frequency,
        ];
    }

    /**
     * @return array{category: ?string, brand: ?string, selling_price: ?float}
     */
    private function attrsRow(?string $category, ?string $brand, mixed $sellingPrice): array
    {
        return [
            'category' => $category,
            'brand' => $brand,
            'selling_price' => $sellingPrice !== null ? (float) $sellingPrice : null,
        ];
    }

    private function getTopRecommendedProducts(int $limit): array
    {
        $popular = $this->popularProducts($limit);

        return array_map(fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'sku' => $row['sku'],
            'selling_price' => $row['selling_price'],
        ], $popular);
    }

    // -------------------------------------------------------------------------
    // Configuration & caching
    // -------------------------------------------------------------------------

    private function algorithm(): string
    {
        $algorithm = (string) $this->getSetting('recommendation_algorithm', 'hybrid');

        return in_array($algorithm, ['hybrid', 'item_based', 'content_based'], true)
            ? $algorithm
            : 'hybrid';
    }

    private function resultCount(): int
    {
        return min(50, max(1, (int) $this->getSetting('recommendation_result_count', 5)));
    }

    private function clampLimit(int $limit): int
    {
        return max(1, min(20, $limit));
    }

    private function minCoOccurrence(): int
    {
        return max(1, (int) $this->getSetting('recommendation_min_co_occurrence', 2));
    }

    private function lookbackDays(): int
    {
        return min(730, max(7, (int) $this->getSetting('recommendation_lookback_days', 90)));
    }

    private function since(): string
    {
        return Carbon::now()->subDays($this->lookbackDays())->toDateTimeString();
    }

    private function validStatuses(): array
    {
        return array_map(fn (OrderStatus $s) => $s->value, self::VALID_STATUSES);
    }

    private function cacheEnabled(): bool
    {
        return filter_var($this->getSetting('recommendation_cache_enabled', true), FILTER_VALIDATE_BOOL);
    }

    private function cacheTtlSeconds(): int
    {
        return min(86400, max(30, (int) $this->getSetting('recommendation_cache_ttl_seconds', self::CACHE_TTL)));
    }

    /**
     * Cache helper honouring the enabled flag and configured TTL; keyed by
     * the shared version counter that clearCache() bumps.
     *
     * @template T
     *
     * @param  callable(): T  $produce
     * @return T
     */
    private function remember(string $suffix, callable $produce): mixed
    {
        if (! $this->cacheEnabled()) {
            return $produce();
        }

        $key = 'product_recs:'.$this->cacheVersion().':'.md5($suffix);

        return Cache::remember($key, $this->cacheTtlSeconds(), $produce);
    }

    private function cacheVersion(): int
    {
        return (int) Cache::get('product_recs_cache_version', 0);
    }

    private function getSetting(string $key, mixed $default = null): mixed
    {
        return SiteSetting::get($key, $default);
    }
}
