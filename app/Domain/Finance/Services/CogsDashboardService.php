<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\CogsDailySummary;
use App\Domain\Finance\Models\CogsEntry;
use App\Domain\Finance\Models\CogsVarianceAlert;
use App\Domain\Product\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CogsDashboardService
{
    private const VARIANCE_THRESHOLD_PCT = 5.0;

    /**
     * Generate (or regenerate) daily summaries for a given date.
     * Aggregates cogs_entries by product for that date.
     */
    public function generateDailySummary(string $date): int
    {
        $rows = DB::table('cogs_entries')
            ->select(
                'product_id',
                'variant_id',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(total_cost) as total_cost'),
                DB::raw('COUNT(*) as entries_count'),
                DB::raw('COUNT(DISTINCT order_id) as orders_count'),
            )
            ->whereDate('recorded_at', $date)
            ->groupBy('product_id', 'variant_id')
            ->get();

        $created = 0;

        foreach ($rows as $row) {
            $product = Product::find($row->product_id);
            $standardCost = (float) ($product?->cost_price ?? 0);
            $totalQty = (float) $row->total_quantity;
            $totalCost = (float) $row->total_cost;
            $avgUnitCost = $totalQty > 0 ? round($totalCost / $totalQty, 4) : 0;

            $varianceAmount = round($totalCost - ($standardCost * $totalQty), 4);
            $variancePct = $standardCost > 0
                ? round((($avgUnitCost - $standardCost) / $standardCost) * 100, 4)
                : 0;

            CogsDailySummary::updateOrCreate(
                [
                    'summary_date' => $date,
                    'product_id' => $row->product_id,
                    'variant_id' => $row->variant_id,
                ],
                [
                    'total_quantity' => $totalQty,
                    'total_cost' => $totalCost,
                    'avg_unit_cost' => $avgUnitCost,
                    'standard_cost' => $standardCost,
                    'variance_amount' => $varianceAmount,
                    'variance_pct' => $variancePct,
                    'entries_count' => (int) $row->entries_count,
                    'orders_count' => (int) $row->orders_count,
                ],
            );

            $created++;

            // Generate variance alert if threshold exceeded
            if ($standardCost > 0 && abs($variancePct) >= self::VARIANCE_THRESHOLD_PCT) {
                $this->createVarianceAlert(
                    $date,
                    (int) $row->product_id,
                    $row->variant_id ? (int) $row->variant_id : null,
                    $avgUnitCost,
                    $standardCost,
                    $varianceAmount,
                    $variancePct,
                    (int) $row->entries_count,
                );
            }
        }

        Log::info("COGS daily summary generated for {$date}: {$created} product summaries");

        return $created;
    }

    /**
     * Create a variance alert (or update if already exists for that date+product).
     */
    private function createVarianceAlert(
        string $date,
        int $productId,
        ?int $variantId,
        float $actualCost,
        float $standardCost,
        float $varianceAmount,
        float $variancePct,
        int $affectedEntries,
    ): void {
        $severity = abs($variancePct) >= 20 ? 'HIGH' : (abs($variancePct) >= 10 ? 'MEDIUM' : 'LOW');

        $product = Product::find($productId);
        $productName = $product?->name ?? "Product #{$productId}";

        $direction = $varianceAmount > 0 ? 'higher' : 'lower';
        $message = "Actual COGS (₱{$actualCost}) is {$variancePct}% {$direction} than standard cost (₱{$standardCost}) for {$productName}.";

        CogsVarianceAlert::updateOrCreate(
            [
                'alert_date' => $date,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'alert_type' => 'COST_VARIANCE',
            ],
            [
                'severity' => $severity,
                'actual_cost' => $actualCost,
                'standard_cost' => $standardCost,
                'variance_amount' => $varianceAmount,
                'variance_pct' => $variancePct,
                'affected_entries' => $affectedEntries,
                'message' => $message,
                'resolved' => false,
            ],
        );
    }

    /**
     * Get dashboard data: real-time stats + recent summaries + active alerts.
     */
    public function getDashboardData(array $filters = []): array
    {
        $days = (int) ($filters['days'] ?? 30);
        $fromDate = now()->subDays($days)->toDateString();

        // Real-time totals from cogs_entries (no pre-aggregation needed)
        $todayStats = $this->getRealtimeStats(now()->toDateString());
        $periodStats = $this->getRealtimeStats($fromDate, now()->toDateString());

        // Daily trend (last N days)
        $trend = CogsDailySummary::select(
            'summary_date',
            DB::raw('SUM(total_cost) as daily_cost'),
            DB::raw('SUM(total_quantity) as daily_quantity'),
            DB::raw('SUM(orders_count) as daily_orders'),
            DB::raw('SUM(variance_amount) as daily_variance'),
        )
            ->where('summary_date', '>=', $fromDate)
            ->groupBy('summary_date')
            ->orderBy('summary_date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->summary_date instanceof Carbon ? $r->summary_date->toDateString() : (string) $r->summary_date,
                'cost' => (float) $r->daily_cost,
                'quantity' => (float) $r->daily_quantity,
                'orders' => (int) $r->daily_orders,
                'variance' => (float) $r->daily_variance,
            ]);

        // Top products by COGS in period
        $topProducts = CogsDailySummary::with('product:id,sku,name')
            ->select('product_id', DB::raw('SUM(total_cost) as total_cost'), DB::raw('SUM(total_quantity) as total_quantity'))
            ->where('summary_date', '>=', $fromDate)
            ->groupBy('product_id')
            ->orderByDesc('total_cost')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'sku' => $r->product?->sku,
                'name' => $r->product?->name,
                'total_cost' => (float) $r->total_cost,
                'total_quantity' => (float) $r->total_quantity,
            ]);

        // Active variance alerts
        $alerts = $this->getVarianceAlerts($filters);

        return [
            'today' => $todayStats,
            'period' => $periodStats,
            'trend' => $trend,
            'top_products' => $topProducts,
            'alerts' => $alerts,
            'days' => $days,
        ];
    }

    /**
     * Get real-time stats directly from cogs_entries (no pre-aggregation).
     */
    public function getRealtimeStats(string $from, ?string $to = null): array
    {
        $query = CogsEntry::whereDate('recorded_at', '>=', $from);
        if ($to) {
            $query->whereDate('recorded_at', '<=', $to);
        }

        $totalCost = (float) (clone $query)->sum('total_cost');
        $totalQty = (float) (clone $query)->sum('quantity');
        $entriesCount = (int) (clone $query)->count();
        $ordersCount = (int) (clone $query)->whereNotNull('order_id')->distinct('order_id')->count('order_id');
        $avgUnitCost = $totalQty > 0 ? round($totalCost / $totalQty, 4) : 0;

        // Unsynced entries count
        $unsyncedCount = (int) (clone $query)->whereNull('synced_to_qbo_at')->count();
        $unsyncedCost = (float) (clone $query)->whereNull('synced_to_qbo_at')->sum('total_cost');

        return [
            'total_cost' => $totalCost,
            'total_quantity' => $totalQty,
            'avg_unit_cost' => $avgUnitCost,
            'entries_count' => $entriesCount,
            'orders_count' => $ordersCount,
            'unsynced_count' => $unsyncedCount,
            'unsynced_cost' => $unsyncedCost,
        ];
    }

    /**
     * Get variance alerts with filtering.
     */
    public function getVarianceAlerts(array $filters = []): array
    {
        $query = CogsVarianceAlert::with('product:id,sku,name');

        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (! empty($filters['resolved'])) {
            $query->where('resolved', $filters['resolved'] === 'true');
        } else {
            $query->where('resolved', false);
        }

        $days = (int) ($filters['days'] ?? 30);
        $query->where('alert_date', '>=', now()->subDays($days)->toDateString());

        return $query->orderByDesc('alert_date')
            ->orderByDesc('severity')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'alert_date' => $a->alert_date instanceof Carbon ? $a->alert_date->toDateString() : (string) $a->alert_date,
                'product_id' => $a->product_id,
                'sku' => $a->product?->sku,
                'name' => $a->product?->name,
                'severity' => $a->severity,
                'alert_type' => $a->alert_type,
                'actual_cost' => (float) $a->actual_cost,
                'standard_cost' => (float) $a->standard_cost,
                'variance_amount' => (float) $a->variance_amount,
                'variance_pct' => (float) $a->variance_pct,
                'affected_entries' => $a->affected_entries,
                'message' => $a->message,
                'resolved' => $a->resolved,
                'resolved_at' => $a->resolved_at?->toDateTimeString(),
            ])
            ->toArray();
    }

    /**
     * Resolve a variance alert.
     */
    public function resolveAlert(int $alertId, int $userId, ?string $note = null): CogsVarianceAlert
    {
        $alert = CogsVarianceAlert::findOrFail($alertId);
        $alert->update([
            'resolved' => true,
            'resolved_at' => now(),
            'resolved_by' => $userId,
            'resolution_note' => $note,
        ]);

        return $alert->fresh();
    }

    /**
     * Get daily summary detail for a specific date.
     */
    public function getDailySummaryDetail(string $date): array
    {
        $summaries = CogsDailySummary::with(['product:id,sku,name', 'variant:id,sku'])
            ->where('summary_date', $date)
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'product_id' => $s->product_id,
                'sku' => $s->product?->sku,
                'name' => $s->product?->name,
                'variant_sku' => $s->variant?->sku,
                'total_quantity' => (float) $s->total_quantity,
                'total_cost' => (float) $s->total_cost,
                'avg_unit_cost' => (float) $s->avg_unit_cost,
                'standard_cost' => (float) $s->standard_cost,
                'variance_amount' => (float) $s->variance_amount,
                'variance_pct' => (float) $s->variance_pct,
                'entries_count' => $s->entries_count,
                'orders_count' => $s->orders_count,
            ]);

        return [
            'date' => $date,
            'summaries' => $summaries,
            'totals' => [
                'total_cost' => (float) $summaries->sum('total_cost'),
                'total_quantity' => (float) $summaries->sum('total_quantity'),
                'products_count' => $summaries->count(),
                'total_variance' => (float) $summaries->sum('variance_amount'),
            ],
        ];
    }
}
