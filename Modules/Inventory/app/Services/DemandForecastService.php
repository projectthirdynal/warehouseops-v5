<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Enums\OrderStatus;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductStock;
use Modules\Shop\Models\ShopOrderItem;

class DemandForecastService
{
    private const DEFAULT_HISTORY_DAYS = 90;

    private const MIN_SALE_DAYS_FOR_TREND = 5;

    private const MIN_HISTORY_DAYS_FOR_TREND = 14;

    /**
     * Get a summary list of products with sales history, basic trend, and
     * simple forecasted demand for the next 30 days.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForecastSummaryList(int $limit = 50, int $historyDays = 60): array
    {
        $cutoff = Carbon::now()->subDays($historyDays)->startOfDay();
        $midpoint = Carbon::now()->subDays((int) floor($historyDays / 2))->startOfDay();

        $rows = ShopOrderItem::query()
            ->join('orders', 'shop_order_items.order_id', '=', 'orders.id')
            ->where('orders.status', OrderStatus::DELIVERED)
            ->where('orders.created_at', '>=', $cutoff)
            ->whereNotNull('shop_order_items.product_id')
            ->selectRaw('
                shop_order_items.product_id,
                SUM(shop_order_items.quantity) as total_qty,
                SUM(CASE WHEN orders.created_at >= ? THEN shop_order_items.quantity ELSE 0 END) as recent_qty,
                SUM(CASE WHEN orders.created_at < ? THEN shop_order_items.quantity ELSE 0 END) as prior_qty
            ', [$midpoint, $midpoint])
            ->groupBy('shop_order_items.product_id')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get()
            ->keyBy('product_id');

        if ($rows->isEmpty()) {
            return [];
        }

        $productIds = $rows->keys()->all();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $stockByProduct = ProductStock::whereIn('product_id', $productIds)
            ->select('product_id', DB::raw('SUM(current_stock) as total_stock'), DB::raw('SUM(reserved_stock) as total_reserved'), DB::raw('MAX(reorder_point) as reorder_point'))
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $halfDays = max(1, (int) floor($historyDays / 2));
        $summary = [];

        foreach ($rows as $productId => $row) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $recentQty = (int) $row->recent_qty;
            $priorQty = (int) $row->prior_qty;
            $totalQty = (int) $row->total_qty;

            $avgDailyQty = $recentQty / $halfDays;
            $growthRate = $priorQty > 0
                ? (($recentQty - $priorQty) / $priorQty) * 100
                : ($recentQty > 0 ? 100.0 : 0.0);

            $trendDirection = 'stable';
            if ($growthRate > 10) {
                $trendDirection = 'increasing';
            } elseif ($growthRate < -10) {
                $trendDirection = 'decreasing';
            }

            $forecast30d = (int) round($avgDailyQty * 30 * max(0.5, 1 + ($growthRate / 100)));

            $stock = $stockByProduct->get($productId);
            $currentStock = (int) ($stock->total_stock ?? 0);
            $reserved = (int) ($stock->total_reserved ?? 0);
            $available = max(0, $currentStock - $reserved);
            $reorderPoint = (int) ($stock->reorder_point ?? 0);

            $suggestedReorderQty = max(0, $forecast30d - $available);

            $summary[] = [
                'product_id' => $productId,
                'sku' => $product->sku,
                'name' => $product->name,
                'total_historical_qty' => $totalQty,
                'avg_daily_qty' => round($avgDailyQty, 2),
                'growth_rate' => round($growthRate, 1),
                'trend_direction' => $trendDirection,
                'forecast_30d_qty' => $forecast30d,
                'current_stock' => $currentStock,
                'available_stock' => $available,
                'reorder_point' => $reorderPoint,
                'suggested_reorder_qty' => $suggestedReorderQty,
                'needs_reorder' => $suggestedReorderQty > 0 || $available <= $reorderPoint,
            ];
        }

        usort($summary, fn ($a, $b) => $b['total_historical_qty'] <=> $a['total_historical_qty']);

        return $summary;
    }

    /**
     * Get a detailed forecast for a single product: daily history, linear
     * trend, day-of-week seasonality, and a forward-looking forecast.
     *
     * @return array<string, mixed>
     */
    public function getProductForecastDetail(int $productId, int $forecastDays = 30): array
    {
        $product = Product::findOrFail($productId);
        $historyDays = max($forecastDays * 3, self::DEFAULT_HISTORY_DAYS);
        $cutoff = Carbon::now()->subDays($historyDays)->startOfDay();

        $salesByDate = ShopOrderItem::query()
            ->join('orders', 'shop_order_items.order_id', '=', 'orders.id')
            ->where('orders.status', OrderStatus::DELIVERED)
            ->where('shop_order_items.product_id', $productId)
            ->where('orders.created_at', '>=', $cutoff)
            ->selectRaw('DATE(orders.created_at) as date, SUM(shop_order_items.quantity) as qty')
            ->groupByRaw('DATE(orders.created_at)')
            ->pluck('qty', 'date');

        // Build a complete daily series, filling gaps with zero sales.
        $dates = [];
        $quantities = [];
        $cursor = $cutoff->copy();
        $today = Carbon::now()->startOfDay();
        while ($cursor->lte($today)) {
            $dateKey = $cursor->toDateString();
            $dates[] = $dateKey;
            $quantities[] = (int) ($salesByDate[$dateKey] ?? 0);
            $cursor->addDay();
        }

        $n = count($quantities);
        $saleDayCount = count(array_filter($quantities, fn ($q) => $q > 0));
        $dataSufficient = $n >= self::MIN_HISTORY_DAYS_FOR_TREND && $saleDayCount >= self::MIN_SALE_DAYS_FOR_TREND;

        $totalQty = array_sum($quantities);
        $avgDailyQty = $n > 0 ? $totalQty / $n : 0.0;

        $slope = 0.0;
        $intercept = $avgDailyQty;
        $growthRate = 0.0;
        $trendDirection = 'stable';
        $dowFactors = array_fill(0, 7, 1.0);

        if ($dataSufficient) {
            $x = range(0, $n - 1);
            $sumX = array_sum($x);
            $sumY = array_sum($quantities);
            $sumXY = 0;
            $sumX2 = 0;
            foreach ($x as $i => $xi) {
                $sumXY += $xi * $quantities[$i];
                $sumX2 += $xi * $xi;
            }
            $denominator = ($n * $sumX2 - $sumX * $sumX);
            $slope = $denominator != 0 ? ($n * $sumXY - $sumX * $sumY) / $denominator : 0.0;
            $intercept = $n > 0 ? ($sumY - $slope * $sumX) / $n : 0.0;

            $growthRate = $avgDailyQty > 0 ? ($slope / $avgDailyQty) * 100 : 0.0;
            if ($growthRate > 2) {
                $trendDirection = 'increasing';
            } elseif ($growthRate < -2) {
                $trendDirection = 'decreasing';
            }

            $dowTotals = array_fill(0, 7, 0.0);
            $dowCounts = array_fill(0, 7, 0);
            foreach ($dates as $i => $dateStr) {
                $dow = Carbon::parse($dateStr)->dayOfWeek;
                $dowTotals[$dow] += $quantities[$i];
                $dowCounts[$dow]++;
            }
            foreach ($dowTotals as $i => $total) {
                $dowFactors[$i] = $dowCounts[$i] > 0 && $avgDailyQty > 0
                    ? ($total / $dowCounts[$i]) / $avgDailyQty
                    : 1.0;
            }
        }

        $forecast = [];
        $totalForecastQty = 0;
        for ($f = 1; $f <= $forecastDays; $f++) {
            $futureX = $n + $f - 1;
            $futureDate = Carbon::now()->addDays($f);
            $dow = $futureDate->dayOfWeek;
            $dowMultiplier = $dowFactors[$dow] > 0 ? $dowFactors[$dow] : 1.0;

            $baseQty = $dataSufficient ? ($intercept + $slope * $futureX) : $avgDailyQty;
            $predictedQty = max(0, $baseQty * $dowMultiplier);
            $confidence = $dataSufficient
                ? max(10, min(95, 95 - ($f / $forecastDays * 40)))
                : 30.0;

            $forecast[] = [
                'date' => $futureDate->toDateString(),
                'day' => $futureDate->format('D'),
                'predicted_qty' => (int) round($predictedQty),
                'confidence' => round($confidence, 1),
            ];
            $totalForecastQty += $predictedQty;
        }

        $stock = ProductStock::where('product_id', $productId)
            ->select(DB::raw('SUM(current_stock) as total_stock'), DB::raw('SUM(reserved_stock) as total_reserved'), DB::raw('MAX(reorder_point) as reorder_point'))
            ->first();
        $currentStock = (int) ($stock->total_stock ?? 0);
        $reserved = (int) ($stock->total_reserved ?? 0);
        $available = max(0, $currentStock - $reserved);
        $reorderPoint = (int) ($stock->reorder_point ?? 0);
        $suggestedReorderQty = max(0, (int) round($totalForecastQty) - $available);

        return [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
            ],
            'history' => array_map(fn ($date, $qty) => ['date' => $date, 'qty' => $qty], $dates, $quantities),
            'summary' => [
                'total_historical_qty' => $totalQty,
                'avg_daily_qty' => round($avgDailyQty, 2),
                'growth_rate' => round($growthRate, 1),
                'trend_direction' => $trendDirection,
                'data_sufficient' => $dataSufficient,
                'history_days' => $n,
                'sale_day_count' => $saleDayCount,
            ],
            'forecast' => $forecast,
            'total_forecast_qty' => (int) round($totalForecastQty),
            'stock' => [
                'current_stock' => $currentStock,
                'reserved_stock' => $reserved,
                'available_stock' => $available,
                'reorder_point' => $reorderPoint,
                'suggested_reorder_qty' => $suggestedReorderQty,
                'needs_reorder' => $suggestedReorderQty > 0 || $available <= $reorderPoint,
            ],
        ];
    }
}
