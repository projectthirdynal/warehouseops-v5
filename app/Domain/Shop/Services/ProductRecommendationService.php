<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductRecommendationService
{
    private const CACHE_TTL = 300;

    private const VALID_STATUSES = [
        OrderStatus::DELIVERED,
        OrderStatus::CONFIRMED,
        OrderStatus::QA_APPROVED,
        OrderStatus::DISPATCHED,
    ];

    public function recommend(array $productIds, int $limit = 5): array
    {
        $productIds = array_values(array_unique(array_filter($productIds, fn ($id) => $id > 0)));
        if (empty($productIds)) {
            return [];
        }

        $algorithm = $this->getSetting('recommendation_algorithm', 'hybrid');
        $limit = max(1, min(20, $limit));
        $cacheKey = 'product_recs:' . md5(implode(',', $productIds) . ':' . $algorithm . ':' . $limit);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($productIds, $algorithm, $limit) {
            return match ($algorithm) {
                'item_based' => $this->itemBasedRecommend($productIds, $limit),
                'content_based' => $this->contentBasedRecommend($productIds, $limit),
                'hybrid' => $this->hybridRecommend($productIds, $limit),
                default => $this->hybridRecommend($productIds, $limit),
            };
        });
    }

    public function recommendForCustomer(int $customerId, int $limit = 5): array
    {
        $cacheKey = 'customer_recs:' . $customerId . ':' . $limit;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($customerId, $limit) {
            $productIds = Order::query()
                ->where('customer_id', $customerId)
                ->whereIn('status', array_map(fn ($s) => $s->value, self::VALID_STATUSES))
                ->with('shopItems:order_id,product_id')
                ->get(['id'])
                ->flatMap(fn ($order) => $order->shopItems->pluck('product_id'))
                ->unique()
                ->values()
                ->toArray();

            if (empty($productIds)) {
                return [];
            }

            return $this->recommend($productIds, $limit);
        });
    }

    public function getStats(): array
    {
        $totalProducts = Product::where('is_active', true)->count();
        $totalOrders = Order::whereIn('status', array_map(fn ($s) => $s->value, self::VALID_STATUSES))->count();
        $totalOrderItems = ShopOrderItem::count();
        $uniqueProductsOrdered = ShopOrderItem::distinct('product_id')->count('product_id');
        $avgItemsPerOrder = $totalOrders > 0 ? round($totalOrderItems / $totalOrders, 2) : 0;

        return [
            'total_products' => $totalProducts,
            'total_orders' => $totalOrders,
            'total_order_items' => $totalOrderItems,
            'unique_products_ordered' => $uniqueProductsOrdered,
            'avg_items_per_order' => $avgItemsPerOrder,
            'top_recommended' => $this->getTopRecommendedProducts(10),
            'top_co_occurring' => $this->getTopCoOccurringPairs(5),
            'algorithm' => $this->getSetting('recommendation_algorithm', 'hybrid'),
            'cache_enabled' => (bool) $this->getSetting('recommendation_cache_enabled', true),
            'result_count' => (int) $this->getSetting('recommendation_result_count', 5),
            'coverage' => $totalProducts > 0 ? round($uniqueProductsOrdered / $totalProducts * 100, 1) : 0,
        ];
    }

    public function getSettings(): array
    {
        return [
            'algorithm' => $this->getSetting('recommendation_algorithm', 'hybrid'),
            'cache_enabled' => (bool) $this->getSetting('recommendation_cache_enabled', true),
            'result_count' => (int) $this->getSetting('recommendation_result_count', 5),
            'min_co_occurrence' => (int) $this->getSetting('recommendation_min_co_occurrence', 2),
            'lookback_days' => (int) $this->getSetting('recommendation_lookback_days', 90),
        ];
    }

    public function updateSettings(array $settings): array
    {
        $allowed = ['algorithm', 'cache_enabled', 'result_count', 'min_co_occurrence', 'lookback_days'];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $settings)) {
                SiteSetting::set('recommendation_' . $key, $settings[$key]);
            }
        }

        $this->clearCache();

        return $this->getSettings();
    }

    public function clearCache(): int
    {
        $cleared = 0;
        $prefix = config('cache.prefix', '');
        $redis = Cache::getRedis();

        foreach (['product_recs:*', 'customer_recs:*'] as $pattern) {
            $fullPattern = $prefix ? $prefix . ':' . $pattern : $pattern;
            $keys = $redis->keys($fullPattern);
            if (!empty($keys)) {
                $redis->del($keys);
                $cleared += count($keys);
            }
        }

        return $cleared;
    }

    private function itemBasedRecommend(array $productIds, int $limit): array
    {
        $minCoOccurrence = (int) $this->getSetting('recommendation_min_co_occurrence', 2);

        $orderIds = ShopOrderItem::query()
            ->whereIn('product_id', $productIds)
            ->pluck('order_id')
            ->unique()
            ->take(1000);

        if ($orderIds->isEmpty()) {
            return [];
        }

        $recommendations = ShopOrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->whereNotIn('product_id', $productIds)
            ->select('product_id', DB::raw('COUNT(*) as frequency'))
            ->groupBy('product_id')
            ->having('frequency', '>=', $minCoOccurrence)
            ->orderByDesc('frequency')
            ->limit($limit * 2)
            ->get();

        if ($recommendations->isEmpty()) {
            return [];
        }

        $scores = $recommendations->map(function ($item) use ($productIds) {
            $cosineSim = $this->cosineSimilarity($productIds, [$item->product_id]);
            return [
                'product_id' => $item->product_id,
                'score' => $cosineSim,
                'frequency' => $item->frequency,
            ];
        })->sortByDesc('score')->take($limit);

        return $this->formatResults($scores->values()->toArray());
    }

    private function contentBasedRecommend(array $productIds, int $limit): array
    {
        $sourceProducts = Product::whereIn('id', $productIds)->where('is_active', true)->get();

        if ($sourceProducts->isEmpty()) {
            return [];
        }

        $categories = $sourceProducts->pluck('category')->filter()->unique()->values()->toArray();
        $brands = $sourceProducts->pluck('brand')->filter()->unique()->values()->toArray();

        $query = Product::query()
            ->where('is_active', true)
            ->whereNotIn('id', $productIds);

        $query->where(function ($q) use ($categories, $brands) {
            if (!empty($categories)) {
                $q->whereIn('category', $categories);
            }
            if (!empty($brands)) {
                $q->orWhereIn('brand', $brands);
            }
        });

        $candidates = $query->limit($limit * 3)->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $scores = $candidates->map(function ($product) use ($sourceProducts) {
            $score = 0;
            foreach ($sourceProducts as $source) {
                if ($product->category && $source->category && $product->category === $source->category) {
                    $score += 0.5;
                }
                if ($product->brand && $source->brand && $product->brand === $source->brand) {
                    $score += 0.3;
                }
                $priceDiff = abs((float) $product->selling_price - (float) $source->selling_price);
                $maxPrice = max((float) $product->selling_price, (float) $source->selling_price, 1);
                $priceSim = 1 - ($priceDiff / $maxPrice);
                $score += $priceSim * 0.2;
            }
            return [
                'product_id' => $product->id,
                'score' => round($score / $sourceProducts->count(), 4),
                'frequency' => 0,
            ];
        })->sortByDesc('score')->take($limit);

        return $this->formatResults($scores->values()->toArray());
    }

    private function hybridRecommend(array $productIds, int $limit): array
    {
        $itemBased = $this->itemBasedRecommend($productIds, $limit * 2);
        $contentBased = $this->contentBasedRecommend($productIds, $limit * 2);

        $merged = [];

        foreach ($itemBased as $item) {
            $pid = $item['id'];
            $merged[$pid] = [
                'product_id' => $pid,
                'score' => $item['score'] * 0.65,
                'frequency' => $item['frequency'] ?? 0,
            ];
        }

        foreach ($contentBased as $item) {
            $pid = $item['id'];
            if (isset($merged[$pid])) {
                $merged[$pid]['score'] += $item['score'] * 0.35;
            } else {
                $merged[$pid] = [
                    'product_id' => $pid,
                    'score' => $item['score'] * 0.35,
                    'frequency' => 0,
                ];
            }
        }

        usort($merged, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $this->formatResults(array_slice($merged, 0, $limit));
    }

    private function cosineSimilarity(array $setA, array $setB): float
    {
        $ordersA = ShopOrderItem::whereIn('product_id', $setA)->pluck('order_id')->unique()->count();
        $ordersB = ShopOrderItem::whereIn('product_id', $setB)->pluck('order_id')->unique()->count();
        $ordersBoth = ShopOrderItem::query()
            ->whereIn('product_id', $setA)
            ->whereIn('order_id', function ($q) use ($setB) {
                $q->select('order_id')->from('shop_order_items')->whereIn('product_id', $setB);
            })
            ->distinct('order_id')
            ->count('order_id');

        if ($ordersA === 0 || $ordersB === 0) {
            return 0.0;
        }

        return round($ordersBoth / sqrt($ordersA * $ordersB), 4);
    }

    private function formatResults(array $scored): array
    {
        if (empty($scored)) {
            return [];
        }

        $productIds = array_column($scored, 'product_id');
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get(['id', 'sku', 'name', 'brand', 'category', 'selling_price', 'image_url'])
            ->keyBy('id');

        $results = [];
        foreach ($scored as $item) {
            $pid = $item['product_id'];
            if (!$products->has($pid)) {
                continue;
            }
            $p = $products[$pid];
            $results[] = [
                'id' => $pid,
                'sku' => $p->sku,
                'name' => $p->name,
                'brand' => $p->brand,
                'category' => $p->category,
                'selling_price' => (float) $p->selling_price,
                'image_url' => $p->image_url,
                'score' => round($item['score'], 4),
                'frequency' => $item['frequency'] ?? 0,
            ];
        }

        return $results;
    }

    private function getTopRecommendedProducts(int $limit): array
    {
        $productIds = ShopOrderItem::query()
            ->select('product_id', DB::raw('COUNT(*) as freq'))
            ->groupBy('product_id')
            ->orderByDesc('freq')
            ->limit($limit)
            ->pluck('product_id');

        $products = Product::whereIn('id', $productIds)
            ->where('is_active', true)
            ->get(['id', 'sku', 'name', 'selling_price'])
            ->keyBy('id');

        return $productIds
            ->filter(fn ($id) => $products->has($id))
            ->map(fn ($id) => [
                'id' => $id,
                'name' => $products[$id]->name,
                'sku' => $products[$id]->sku,
                'selling_price' => (float) $products[$id]->selling_price,
            ])
            ->values()
            ->toArray();
    }

    private function getTopCoOccurringPairs(int $limit): array
    {
        $pairs = DB::table('shop_order_items as a')
            ->join('shop_order_items as b', 'a.order_id', '=', 'b.order_id')
            ->where('a.product_id', '<', 'b.product_id')
            ->select('a.product_id as pid_a', 'b.product_id as pid_b', DB::raw('COUNT(*) as co_occurrence'))
            ->groupBy('a.product_id', 'b.product_id')
            ->orderByDesc('co_occurrence')
            ->limit($limit)
            ->get();

        $allIds = $pairs->flatMap(fn ($p) => [$p->pid_a, $p->pid_b])->unique()->values()->all();
        $products = Product::whereIn('id', $allIds)->get(['id', 'name'])->keyBy('id');

        return $pairs->map(function ($p) use ($products) {
            return [
                'product_a' => $products->has($p->pid_a) ? $products[$p->pid_a]->name : "Product #{$p->pid_a}",
                'product_b' => $products->has($p->pid_b) ? $products[$p->pid_b]->name : "Product #{$p->pid_b}",
                'co_occurrence' => $p->co_occurrence,
            ];
        })->toArray();
    }

    private function getSetting(string $key, mixed $default = null): mixed
    {
        return SiteSetting::get($key, $default);
    }
}
