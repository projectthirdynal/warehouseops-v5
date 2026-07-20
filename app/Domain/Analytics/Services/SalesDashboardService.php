<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Provides aggregated sales metrics for dashboard display.
 */
class SalesDashboardService
{
    /**
     * Get order counts broken down by daily, weekly, and monthly periods.
     *
     * @return array{
     *     daily: array{date: string, count: int, label: string},
     *     weekly: array{week_start: string, count: int, label: string},
     *     monthly: array{month_start: string, count: int, label: string},
     *     today: int,
     *     yesterday: int,
     *     this_week: int,
     *     last_week: int,
     *     this_month: int,
     *     last_month: int,
     *     total: int,
     *     today_trend: int|null,
     *     week_trend: int|null,
     *     month_trend: int|null,
     * }
     */
    public function orderCounts(): array
    {
        $today = today();
        $yesterday = today()->subDay();
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();
        $startOfLastWeek = Carbon::now()->subWeek()->startOfWeek();
        $endOfLastWeek = Carbon::now()->subWeek()->endOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();
        $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
        $endOfLastMonth = Carbon::now()->subMonth()->endOfMonth();

        $todayCount = Order::whereDate('created_at', $today)->count();
        $yesterdayCount = Order::whereDate('created_at', $yesterday)->count();
        $thisWeekCount = Order::whereBetween('created_at', [$startOfWeek, $endOfWeek])->count();
        $lastWeekCount = Order::whereBetween('created_at', [$startOfLastWeek, $endOfLastWeek])->count();
        $thisMonthCount = Order::whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();
        $lastMonthCount = Order::whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])->count();
        $totalCount = Order::count();

        $daily = $this->dailyBreakdown(30);
        $weekly = $this->weeklyBreakdown(12);
        $monthly = $this->monthlyBreakdown(12);

        return [
            'daily' => $daily,
            'weekly' => $weekly,
            'monthly' => $monthly,
            'today' => $todayCount,
            'yesterday' => $yesterdayCount,
            'this_week' => $thisWeekCount,
            'last_week' => $lastWeekCount,
            'this_month' => $thisMonthCount,
            'last_month' => $lastMonthCount,
            'total' => $totalCount,
            'today_trend' => $this->trend($todayCount, $yesterdayCount),
            'week_trend' => $this->trend($thisWeekCount, $lastWeekCount),
            'month_trend' => $this->trend($thisMonthCount, $lastMonthCount),
        ];
    }

    /**
     * Daily order count for the last N days.
     *
     * @return array<int, array{date: string, count: int, label: string}>
     */
    private function dailyBreakdown(int $days): array
    {
        $start = today()->subDays($days - 1);

        $raw = Order::selectRaw('DATE(created_at) as date, COUNT(*) as cnt')
            ->whereDate('created_at', '>=', $start)
            ->groupByRaw('DATE(created_at)')
            ->pluck('cnt', 'date');

        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->toDateString();
            $result[] = [
                'date' => $key,
                'count' => (int) ($raw[$key] ?? 0),
                'label' => $date->format('M j'),
            ];
        }

        return $result;
    }

    /**
     * Weekly order count for the last N weeks.
     *
     * @return array<int, array{week_start: string, count: int, label: string}>
     */
    private function weeklyBreakdown(int $weeks): array
    {
        $start = Carbon::now()->subWeeks($weeks - 1)->startOfWeek();

        $raw = Order::selectRaw("DATE_TRUNC('week', created_at) as week, COUNT(*) as cnt")
            ->where('created_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('week', created_at)")
            ->pluck('cnt', 'week');

        $result = [];
        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $start->copy()->addWeeks($i);
            $key = $weekStart->toDateString();
            $result[] = [
                'week_start' => $key,
                'count' => (int) ($raw[$key] ?? 0),
                'label' => $weekStart->format('M j') . ' – ' . $weekStart->copy()->endOfWeek()->format('M j'),
            ];
        }

        return $result;
    }

    /**
     * Monthly order count for the last N months.
     *
     * @return array<int, array{month_start: string, count: int, label: string}>
     */
    private function monthlyBreakdown(int $months): array
    {
        $start = Carbon::now()->subMonths($months - 1)->startOfMonth();

        $raw = Order::selectRaw("DATE_TRUNC('month', created_at) as month, COUNT(*) as cnt")
            ->where('created_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('month', created_at)")
            ->pluck('cnt', 'month');

        $result = [];
        for ($i = 0; $i < $months; $i++) {
            $monthStart = $start->copy()->addMonths($i);
            $key = $monthStart->toDateString();
            $result[] = [
                'month_start' => $key,
                'count' => (int) ($raw[$key] ?? 0),
                'label' => $monthStart->format('M Y'),
            ];
        }

        return $result;
    }

    private function trend(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return null;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    /**
     * Get revenue totals broken down by daily, weekly, and monthly periods.
     *
     * @return array<string, mixed>
     */
    public function revenueTotals(): array
    {
        $today = today();
        $yesterday = today()->subDay();
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();
        $startOfLastWeek = Carbon::now()->subWeek()->startOfWeek();
        $endOfLastWeek = Carbon::now()->subWeek()->endOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();
        $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
        $endOfLastMonth = Carbon::now()->subMonth()->endOfMonth();

        $todayGross = $this->periodGross($today, $today);
        $yesterdayGross = $this->periodGross($yesterday, $yesterday);
        $thisWeekGross = $this->periodGross($startOfWeek, $endOfWeek);
        $lastWeekGross = $this->periodGross($startOfLastWeek, $endOfLastWeek);
        $thisMonthGross = $this->periodGross($startOfMonth, $endOfMonth);
        $lastMonthGross = $this->periodGross($startOfLastMonth, $endOfLastMonth);
        $totalGross = $this->periodGross(Carbon::parse('2000-01-01'), Carbon::now());

        $todayNet = $this->periodNet($today, $today);
        $thisWeekNet = $this->periodNet($startOfWeek, $endOfWeek);
        $thisMonthNet = $this->periodNet($startOfMonth, $endOfMonth);
        $totalNet = $this->periodNet(Carbon::parse('2000-01-01'), Carbon::now());

        return [
            'daily' => $this->dailyRevenue(30),
            'weekly' => $this->weeklyRevenue(12),
            'monthly' => $this->monthlyRevenue(12),
            'today_gross' => $todayGross,
            'yesterday_gross' => $yesterdayGross,
            'this_week_gross' => $thisWeekGross,
            'last_week_gross' => $lastWeekGross,
            'this_month_gross' => $thisMonthGross,
            'last_month_gross' => $lastMonthGross,
            'total_gross' => $totalGross,
            'today_net' => $todayNet,
            'this_week_net' => $thisWeekNet,
            'this_month_net' => $thisMonthNet,
            'total_net' => $totalNet,
            'today_trend' => $this->trend((int) $todayGross, (int) $yesterdayGross),
            'week_trend' => $this->trend((int) $thisWeekGross, (int) $lastWeekGross),
            'month_trend' => $this->trend((int) $thisMonthGross, (int) $lastMonthGross),
        ];
    }

    private function periodGross(Carbon $from, Carbon $to): float
    {
        return (float) Order::where('status', OrderStatus::DELIVERED)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->sum('total_amount');
    }

    private function periodNet(Carbon $from, Carbon $to): float
    {
        $gross = Order::where('status', OrderStatus::DELIVERED)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->sum('total_amount');

        $refunds = Order::where('status', OrderStatus::RETURNED)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->sum('total_amount');

        return (float) $gross - (float) $refunds;
    }

    /**
     * @return array<int, array{date: string, gross: float, net: float, label: string}>
     */
    private function dailyRevenue(int $days): array
    {
        $start = today()->subDays($days - 1);

        $grossRaw = Order::where('status', OrderStatus::DELIVERED)
            ->selectRaw('DATE(created_at) as date, SUM(total_amount) as total')
            ->whereDate('created_at', '>=', $start)
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'date');

        $refundRaw = Order::where('status', OrderStatus::RETURNED)
            ->selectRaw('DATE(created_at) as date, SUM(total_amount) as total')
            ->whereDate('created_at', '>=', $start)
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'date');

        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->toDateString();
            $gross = (float) ($grossRaw[$key] ?? 0);
            $refunds = (float) ($refundRaw[$key] ?? 0);
            $result[] = [
                'date' => $key,
                'gross' => $gross,
                'net' => $gross - $refunds,
                'label' => $date->format('M j'),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{week_start: string, gross: float, net: float, label: string}>
     */
    private function weeklyRevenue(int $weeks): array
    {
        $start = Carbon::now()->subWeeks($weeks - 1)->startOfWeek();

        $grossRaw = Order::where('status', OrderStatus::DELIVERED)
            ->selectRaw("DATE_TRUNC('week', created_at) as week, SUM(total_amount) as total")
            ->where('created_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('week', created_at)")
            ->pluck('total', 'week');

        $refundRaw = Order::where('status', OrderStatus::RETURNED)
            ->selectRaw("DATE_TRUNC('week', created_at) as week, SUM(total_amount) as total")
            ->where('created_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('week', created_at)")
            ->pluck('total', 'week');

        $result = [];
        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $start->copy()->addWeeks($i);
            $key = $weekStart->toDateString();
            $gross = (float) ($grossRaw[$key] ?? 0);
            $refunds = (float) ($refundRaw[$key] ?? 0);
            $result[] = [
                'week_start' => $key,
                'gross' => $gross,
                'net' => $gross - $refunds,
                'label' => $weekStart->format('M j') . ' – ' . $weekStart->copy()->endOfWeek()->format('M j'),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{month_start: string, gross: float, net: float, label: string}>
     */
    private function monthlyRevenue(int $months): array
    {
        $start = Carbon::now()->subMonths($months - 1)->startOfMonth();

        $grossRaw = Order::where('status', OrderStatus::DELIVERED)
            ->selectRaw("DATE_TRUNC('month', created_at) as month, SUM(total_amount) as total")
            ->where('created_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('month', created_at)")
            ->pluck('total', 'month');

        $refundRaw = Order::where('status', OrderStatus::RETURNED)
            ->selectRaw("DATE_TRUNC('month', created_at) as month, SUM(total_amount) as total")
            ->where('created_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('month', created_at)")
            ->pluck('total', 'month');

        $result = [];
        for ($i = 0; $i < $months; $i++) {
            $monthStart = $start->copy()->addMonths($i);
            $key = $monthStart->toDateString();
            $gross = (float) ($grossRaw[$key] ?? 0);
            $refunds = (float) ($refundRaw[$key] ?? 0);
            $result[] = [
                'month_start' => $key,
                'gross' => $gross,
                'net' => $gross - $refunds,
                'label' => $monthStart->format('M Y'),
            ];
        }

        return $result;
    }

    /**
     * Get order status breakdown — counts and percentages per status.
     *
     * @return array{
     *     total: int,
     *     statuses: array<int, array{status: string, label: string, color: string, count: int, percentage: float}>,
     *     terminal_count: int,
     *     active_count: int,
     * }
     */
    public function statusBreakdown(): array
    {
        $total = Order::count();

        $raw = Order::selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $statuses = [];
        $terminalCount = 0;
        $activeCount = 0;

        foreach (OrderStatus::cases() as $case) {
            $count = (int) ($raw[$case->value] ?? 0);

            if ($case->isTerminal()) {
                $terminalCount += $count;
            } else {
                $activeCount += $count;
            }

            $statuses[] = [
                'status' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
                'count' => $count,
                'percentage' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
            ];
        }

        usort($statuses, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'total' => $total,
            'statuses' => $statuses,
            'terminal_count' => $terminalCount,
            'active_count' => $activeCount,
        ];
    }

    /**
     * Get top products by quantity sold.
     *
     * @param int $limit Number of products to return
     * @return array{
     *     total_quantity: int,
     *     total_revenue: float,
     *     products: array<int, array{product_id: int|null, product_name: string, quantity: int, revenue: float, percentage: float}>,
     * }
     */
    public function topProducts(int $limit = 10): array
    {
        $rows = \App\Domain\Shop\Models\ShopOrderItem::query()
            ->join('orders', 'shop_order_items.order_id', '=', 'orders.id')
            ->where('orders.status', OrderStatus::DELIVERED)
            ->selectRaw('
                shop_order_items.product_id,
                shop_order_items.product_name,
                SUM(shop_order_items.quantity) as total_qty,
                SUM(shop_order_items.line_total) as total_revenue
            ')
            ->groupBy('shop_order_items.product_id', 'shop_order_items.product_name')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        $grandQuantity = (int) $rows->sum('total_qty');
        $grandRevenue = (float) $rows->sum('total_revenue');

        $products = $rows->map(fn ($r) => [
            'product_id' => $r->product_id,
            'product_name' => $r->product_name ?? 'Unknown',
            'quantity' => (int) $r->total_qty,
            'revenue' => (float) $r->total_revenue,
            'percentage' => $grandQuantity > 0 ? round((int) $r->total_qty / $grandQuantity * 100, 1) : 0.0,
        ])->toArray();

        return [
            'total_quantity' => $grandQuantity,
            'total_revenue' => $grandRevenue,
            'products' => $products,
        ];
    }

    /**
     * Get sales trend data for charting — combined order counts and revenue
     * with 7-day and 30-day moving averages plus period-over-period growth.
     *
     * @param string $period 'daily', 'weekly', or 'monthly'
     * @param int $points Number of data points
     * @return array<string, mixed>
     */
    public function salesTrends(string $period = 'daily', int $points = 90): array
    {
        return match ($period) {
            'weekly' => $this->weeklyTrends($points),
            'monthly' => $this->monthlyTrends($points),
            default => $this->dailyTrends($points),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function dailyTrends(int $days): array
    {
        $start = today()->subDays($days - 1);

        $orderRaw = Order::selectRaw('DATE(created_at) as date, COUNT(*) as cnt, COALESCE(SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END), 0) as rev', [OrderStatus::DELIVERED->value])
            ->whereDate('created_at', '>=', $start)
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->date)->toDateString());

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->toDateString();
            $row = $orderRaw->get($key);
            $series[] = [
                'date' => $key,
                'label' => $date->format('M j'),
                'orders' => $row ? (int) $row->cnt : 0,
                'revenue' => $row ? (float) $row->rev : 0.0,
            ];
        }

        return $this->buildTrendResponse($series, 'daily', $days);
    }

    /**
     * @return array<string, mixed>
     */
    private function weeklyTrends(int $weeks): array
    {
        $start = Carbon::now()->subWeeks($weeks - 1)->startOfWeek();

        $orderRaw = Order::selectRaw("DATE_TRUNC('week', created_at) as week, COUNT(*) as cnt, COALESCE(SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END), 0) as rev", [OrderStatus::DELIVERED->value])
            ->where('created_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('week', created_at)")
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->week)->toDateString());

        $series = [];
        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $start->copy()->addWeeks($i);
            $key = $weekStart->toDateString();
            $row = $orderRaw->get($key);
            $series[] = [
                'date' => $key,
                'label' => $weekStart->format('M j'),
                'orders' => $row ? (int) $row->cnt : 0,
                'revenue' => $row ? (float) $row->rev : 0.0,
            ];
        }

        return $this->buildTrendResponse($series, 'weekly', $weeks);
    }

    /**
     * @return array<string, mixed>
     */
    private function monthlyTrends(int $months): array
    {
        $start = Carbon::now()->subMonths($months - 1)->startOfMonth();

        $orderRaw = Order::selectRaw("DATE_TRUNC('month', created_at) as month, COUNT(*) as cnt, COALESCE(SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END), 0) as rev", [OrderStatus::DELIVERED->value])
            ->where('created_at', '>=', $start)
            ->groupByRaw("DATE_TRUNC('month', created_at)")
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->month)->toDateString());

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $monthStart = $start->copy()->addMonths($i);
            $key = $monthStart->toDateString();
            $row = $orderRaw->get($key);
            $series[] = [
                'date' => $key,
                'label' => $monthStart->format('M Y'),
                'orders' => $row ? (int) $row->cnt : 0,
                'revenue' => $row ? (float) $row->rev : 0.0,
            ];
        }

        return $this->buildTrendResponse($series, 'monthly', $months);
    }

    /**
     * @param array<int, array{date: string, label: string, orders: int, revenue: float}> $series
     * @return array<string, mixed>
     */
    private function buildTrendResponse(array $series, string $period, int $points): array
    {
        $orders = array_column($series, 'orders');
        $revenues = array_column($series, 'revenue');

        $ma7Orders = $this->movingAverage($orders, 7);
        $ma7Revenue = $this->movingAverage($revenues, 7);
        $ma30Orders = $this->movingAverage($orders, 30);
        $ma30Revenue = $this->movingAverage($revenues, 30);

        $chartData = [];
        for ($i = 0; $i < count($series); $i++) {
            $chartData[] = [
                'date' => $series[$i]['date'],
                'label' => $series[$i]['label'],
                'orders' => $series[$i]['orders'],
                'revenue' => $series[$i]['revenue'],
                'ma7_orders' => $ma7Orders[$i],
                'ma7_revenue' => $ma7Revenue[$i],
                'ma30_orders' => $ma30Orders[$i],
                'ma30_revenue' => $ma30Revenue[$i],
            ];
        }

        $totalOrders = array_sum($orders);
        $totalRevenue = array_sum($revenues);
        $avgOrders = count($orders) > 0 ? round($totalOrders / count($orders), 1) : 0.0;
        $avgRevenue = count($revenues) > 0 ? round($totalRevenue / count($revenues), 2) : 0.0;

        $peakOrders = !empty($orders) ? max($orders) : 0;
        $peakRevenue = !empty($revenues) ? max($revenues) : 0.0;
        $peakOrdersIndex = !empty($orders) ? array_search($peakOrders, $orders) : false;
        $peakRevenueIndex = !empty($revenues) ? array_search($peakRevenue, $revenues) : false;

        $firstHalf = array_slice($orders, 0, (int) floor(count($orders) / 2));
        $secondHalf = array_slice($orders, (int) floor(count($orders) / 2));
        $firstHalfSum = array_sum($firstHalf);
        $secondHalfSum = array_sum($secondHalf);
        $growthRate = $firstHalfSum > 0
            ? round(($secondHalfSum - $firstHalfSum) / $firstHalfSum * 100, 1)
            : null;

        return [
            'period' => $period,
            'points' => $points,
            'chart_data' => $chartData,
            'summary' => [
                'total_orders' => $totalOrders,
                'total_revenue' => (float) $totalRevenue,
                'avg_orders' => $avgOrders,
                'avg_revenue' => $avgRevenue,
                'peak_orders' => $peakOrders,
                'peak_orders_label' => $peakOrdersIndex !== false ? $series[$peakOrdersIndex]['label'] : null,
                'peak_revenue' => (float) $peakRevenue,
                'peak_revenue_label' => $peakRevenueIndex !== false ? $series[$peakRevenueIndex]['label'] : null,
                'growth_rate' => $growthRate,
            ],
        ];
    }

    /**
     * @param array<int, int|float> $values
     * @return array<int, float|null>
     */
    private function movingAverage(array $values, int $window): array
    {
        $result = [];
        $count = count($values);
        for ($i = 0; $i < $count; $i++) {
            if ($i < $window - 1) {
                $result[] = null;
                continue;
            }
            $slice = array_slice($values, $i - $window + 1, $window);
            $result[] = round(array_sum($slice) / $window, 2);
        }
        return $result;
    }

    /**
     * Get revenue breakdown by Facebook page and source channel.
     *
     * @return array<string, mixed>
     */
    public function revenueBySource(): array
    {
        $byPage = $this->revenueByPage();
        $byChannel = $this->revenueByChannel();

        return [
            'by_page' => $byPage,
            'by_channel' => $byChannel,
            'total_revenue' => $byPage['total_revenue'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revenueByPage(): array
    {
        $rows = Order::where('status', OrderStatus::DELIVERED)
            ->whereNotNull('facebook_page_id')
            ->selectRaw('facebook_page_id, COUNT(*) as cnt, SUM(total_amount) as rev')
            ->groupBy('facebook_page_id')
            ->orderByDesc('rev')
            ->get();

        $pageIds = $rows->pluck('facebook_page_id')->unique()->filter();
        $pages = \App\Domain\Shop\Models\FacebookPage::whereIn('page_id', $pageIds)
            ->pluck('page_name', 'page_id');

        $totalRevenue = (float) $rows->sum('rev');
        $totalOrders = (int) $rows->sum('cnt');

        $items = $rows->map(fn ($r) => [
            'page_id' => $r->facebook_page_id,
            'page_name' => $pages[$r->facebook_page_id] ?? 'Unknown Page',
            'orders' => (int) $r->cnt,
            'revenue' => (float) $r->rev,
            'percentage' => $totalRevenue > 0 ? round((float) $r->rev / $totalRevenue * 100, 1) : 0.0,
        ])->toArray();

        return [
            'items' => $items,
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revenueByChannel(): array
    {
        $rows = Order::where('status', OrderStatus::DELIVERED)
            ->selectRaw('source_channel, COUNT(*) as cnt, SUM(total_amount) as rev')
            ->groupBy('source_channel')
            ->orderByDesc('rev')
            ->get();

        $totalRevenue = (float) $rows->sum('rev');
        $totalOrders = (int) $rows->sum('cnt');

        $items = $rows->map(fn ($r) => [
            'channel' => $r->source_channel ?? 'unknown',
            'orders' => (int) $r->cnt,
            'revenue' => (float) $r->rev,
            'percentage' => $totalRevenue > 0 ? round((float) $r->rev / $totalRevenue * 100, 1) : 0.0,
        ])->toArray();

        return [
            'items' => $items,
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
        ];
    }

    /**
     * Get revenue breakdown by payment method.
     *
     * Payment method is determined by:
     * - COD: orders with cod_amount > 0
     * - POS methods (CASH, GCASH, CARD): from ShopOrderItem metadata
     * - Other: orders with no identifiable payment method
     *
     * @return array<string, mixed>
     */
    public function revenueByPaymentMethod(): array
    {
        $delivered = Order::where('status', OrderStatus::DELIVERED)->get();

        $totals = [
            'COD' => ['orders' => 0, 'revenue' => 0.0],
            'CASH' => ['orders' => 0, 'revenue' => 0.0],
            'GCASH' => ['orders' => 0, 'revenue' => 0.0],
            'CARD' => ['orders' => 0, 'revenue' => 0.0],
            'OTHER' => ['orders' => 0, 'revenue' => 0.0],
        ];

        $posOrderIds = $delivered->where('source_channel', 'pos')->pluck('id');
        $posMethods = [];

        if ($posOrderIds->isNotEmpty()) {
            $posMethods = \App\Domain\Shop\Models\ShopOrderItem::whereIn('order_id', $posOrderIds)
                ->whereNotNull('metadata')
                ->get()
                ->groupBy('order_id')
                ->map(fn ($items) => $items->first()?->metadata['pos_payment_method'] ?? null)
                ->filter()
                ->toArray();
        }

        foreach ($delivered as $order) {
            $method = null;

            if (isset($posMethods[$order->id])) {
                $method = strtoupper($posMethods[$order->id]);
            } elseif ((float) $order->cod_amount > 0) {
                $method = 'COD';
            }

            if ($method === null || !isset($totals[$method])) {
                $method = 'OTHER';
            }

            $totals[$method]['orders']++;
            $totals[$method]['revenue'] += (float) $order->total_amount;
        }

        $totalRevenue = array_sum(array_column($totals, 'revenue'));
        $totalOrders = array_sum(array_column($totals, 'orders'));

        $labels = [
            'COD' => 'Cash on Delivery',
            'CASH' => 'Cash',
            'GCASH' => 'GCash',
            'CARD' => 'Card',
            'OTHER' => 'Other',
        ];

        $items = [];
        foreach ($totals as $method => $data) {
            if ($data['orders'] === 0) {
                continue;
            }
            $items[] = [
                'method' => $method,
                'label' => $labels[$method],
                'orders' => $data['orders'],
                'revenue' => round($data['revenue'], 2),
                'percentage' => $totalRevenue > 0 ? round($data['revenue'] / $totalRevenue * 100, 1) : 0.0,
            ];
        }

        usort($items, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'items' => $items,
            'total_revenue' => round($totalRevenue, 2),
            'total_orders' => $totalOrders,
        ];
    }

    /**
     * Get agent sales leaderboard — top agents by delivered orders and revenue.
     *
     * @param int $limit Number of agents to return
     * @return array<string, mixed>
     */
    public function agentLeaderboard(int $limit = 10): array
    {
        $rows = Order::where('status', OrderStatus::DELIVERED)
            ->whereNotNull('assigned_agent_id')
            ->selectRaw('assigned_agent_id, COUNT(*) as cnt, SUM(total_amount) as rev')
            ->groupBy('assigned_agent_id')
            ->orderByDesc('rev')
            ->limit($limit)
            ->get();

        $agentIds = $rows->pluck('assigned_agent_id')->unique()->filter();
        $agents = \App\Models\User::whereIn('id', $agentIds)
            ->pluck('name', 'id');

        $totalRevenue = (float) $rows->sum('rev');
        $totalOrders = (int) $rows->sum('cnt');

        $items = $rows->map(fn ($r, $i) => [
            'rank' => $i + 1,
            'agent_id' => $r->assigned_agent_id,
            'agent_name' => $agents[$r->assigned_agent_id] ?? 'Unknown',
            'orders' => (int) $r->cnt,
            'revenue' => (float) $r->rev,
            'avg_order_value' => $r->cnt > 0 ? round((float) $r->rev / (int) $r->cnt, 2) : 0.0,
            'percentage' => $totalRevenue > 0 ? round((float) $r->rev / $totalRevenue * 100, 1) : 0.0,
        ])->toArray();

        $topAgent = !empty($items) ? $items[0] : null;
        $avgOrders = $totalOrders > 0 ? round($totalOrders / count($items), 1) : 0.0;
        $avgRevenue = $totalOrders > 0 ? round($totalRevenue / count($items), 2) : 0.0;

        return [
            'items' => $items,
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
            'top_agent' => $topAgent,
            'avg_orders_per_agent' => $avgOrders,
            'avg_revenue_per_agent' => $avgRevenue,
        ];
    }
}
