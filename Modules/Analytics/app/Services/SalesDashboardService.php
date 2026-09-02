<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\DashboardWidgetConfig;
use App\Models\ScheduledSalesReport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;
use Modules\Shop\Models\FacebookPage;
use Modules\Shop\Models\ShopOrderItem;

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
                'label' => $weekStart->format('M j').' – '.$weekStart->copy()->endOfWeek()->format('M j'),
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
                'label' => $weekStart->format('M j').' – '.$weekStart->copy()->endOfWeek()->format('M j'),
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
     * @param  int  $limit  Number of products to return
     * @return array{
     *     total_quantity: int,
     *     total_revenue: float,
     *     products: array<int, array{product_id: int|null, product_name: string, quantity: int, revenue: float, percentage: float}>,
     * }
     */
    public function topProducts(int $limit = 10): array
    {
        $rows = ShopOrderItem::query()
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
     * @param  string  $period  'daily', 'weekly', or 'monthly'
     * @param  int  $points  Number of data points
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
     * @param  array<int, array{date: string, label: string, orders: int, revenue: float}>  $series
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

        $peakOrders = ! empty($orders) ? max($orders) : 0;
        $peakRevenue = ! empty($revenues) ? max($revenues) : 0.0;
        $peakOrdersIndex = ! empty($orders) ? array_search($peakOrders, $orders) : false;
        $peakRevenueIndex = ! empty($revenues) ? array_search($peakRevenue, $revenues) : false;

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
     * @param  array<int, int|float>  $values
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
        $pages = FacebookPage::whereIn('page_id', $pageIds)
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
            $posMethods = ShopOrderItem::whereIn('order_id', $posOrderIds)
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

            if ($method === null || ! isset($totals[$method])) {
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
     * @param  int  $limit  Number of agents to return
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
        $agents = User::whereIn('id', $agentIds)
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

        $topAgent = ! empty($items) ? $items[0] : null;
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

    /**
     * Get cohort/retention metrics — groups customers by first-order month
     * and tracks repeat order rates for subsequent months.
     *
     * @param  int  $cohortMonths  Number of months of cohorts to generate
     * @param  int  $retentionMonths  Number of retention periods to track per cohort
     * @return array<string, mixed>
     */
    public function cohortRetention(int $cohortMonths = 12, int $retentionMonths = 6): array
    {
        $cohortStart = Carbon::now()->subMonths($cohortMonths - 1)->startOfMonth();

        $firstOrders = Order::selectRaw('customer_id, MIN(DATE_TRUNC(\'month\', created_at)) as first_month')
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', $cohortStart)
            ->groupBy('customer_id')
            ->pluck('first_month', 'customer_id');

        $allOrders = Order::whereNotNull('customer_id')
            ->where('created_at', '>=', $cohortStart)
            ->selectRaw('customer_id, DATE_TRUNC(\'month\', created_at) as order_month')
            ->get();

        $customerOrderMonths = [];
        foreach ($allOrders as $o) {
            $monthKey = Carbon::parse($o->order_month)->toDateString();
            $customerOrderMonths[$o->customer_id][$monthKey] = true;
        }

        $cohorts = [];
        $totalCustomers = 0;
        $totalRepeatCustomers = 0;

        for ($c = 0; $c < $cohortMonths; $c++) {
            $cohortMonth = $cohortStart->copy()->addMonths($c);
            $cohortKey = $cohortMonth->toDateString();
            $cohortLabel = $cohortMonth->format('M Y');

            $cohortCustomerIds = $firstOrders->filter(
                fn ($fm) => Carbon::parse($fm)->toDateString() === $cohortKey
            )->keys();

            $cohortSize = $cohortCustomerIds->count();
            if ($cohortSize === 0) {
                continue;
            }

            $totalCustomers += $cohortSize;

            $retention = [];
            $repeatCount = 0;

            for ($r = 0; $r <= $retentionMonths; $r++) {
                $targetMonth = $cohortMonth->copy()->addMonths($r);
                $targetKey = $targetMonth->toDateString();

                if ($targetMonth > Carbon::now()->endOfMonth()) {
                    $retention[] = [
                        'month_offset' => $r,
                        'label' => $r === 0 ? 'Month 0' : "Month {$r}",
                        'customers' => null,
                        'rate' => null,
                    ];

                    continue;
                }

                $active = 0;
                foreach ($cohortCustomerIds as $cid) {
                    if (isset($customerOrderMonths[$cid][$targetKey])) {
                        $active++;
                    }
                }

                $rate = $cohortSize > 0 ? round($active / $cohortSize * 100, 1) : 0.0;

                if ($r > 0 && $active > 0) {
                    $repeatCount += $active;
                }

                $retention[] = [
                    'month_offset' => $r,
                    'label' => $r === 0 ? 'Month 0' : "Month {$r}",
                    'customers' => $active,
                    'rate' => $rate,
                ];
            }

            if ($repeatCount > 0) {
                $totalRepeatCustomers += $cohortSize;
            }

            $cohorts[] = [
                'cohort_month' => $cohortKey,
                'cohort_label' => $cohortLabel,
                'cohort_size' => $cohortSize,
                'retention' => $retention,
                'repeat_customers' => $repeatCount,
                'repeat_rate' => $cohortSize > 0 ? round($repeatCount / $cohortSize * 100, 1) : 0.0,
            ];
        }

        $overallRepeatRate = $totalCustomers > 0
            ? round($totalRepeatCustomers / $totalCustomers * 100, 1)
            : 0.0;

        $avgCohortSize = count($cohorts) > 0
            ? round($totalCustomers / count($cohorts), 1)
            : 0;

        $month1Rates = array_filter(
            array_map(fn ($c) => $c['retention'][1]['rate'] ?? null, $cohorts),
            fn ($r) => $r !== null
        );
        $avgMonth1Retention = ! empty($month1Rates)
            ? round(array_sum($month1Rates) / count($month1Rates), 1)
            : 0.0;

        return [
            'cohorts' => $cohorts,
            'summary' => [
                'total_customers' => $totalCustomers,
                'total_repeat_customers' => $totalRepeatCustomers,
                'overall_repeat_rate' => $overallRepeatRate,
                'avg_cohort_size' => $avgCohortSize,
                'avg_month1_retention' => $avgMonth1Retention,
                'cohort_months' => $cohortMonths,
                'retention_months' => $retentionMonths,
            ],
        ];
    }

    /**
     * Get average order value metrics — overall and by period,
     * with trend and distribution bands.
     *
     * @return array<string, mixed>
     */
    public function averageOrderValue(): array
    {
        $delivered = Order::where('status', OrderStatus::DELIVERED);

        $overall = (clone $delivered)->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as rev')->first();
        $overallCount = (int) $overall->cnt;
        $overallRevenue = (float) $overall->rev;
        $overallAov = $overallCount > 0 ? round($overallRevenue / $overallCount, 2) : 0.0;

        $today = (clone $delivered)->whereDate('created_at', today())->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as rev')->first();
        $yesterday = (clone $delivered)->whereDate('created_at', today()->subDay())->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as rev')->first();

        $thisWeek = (clone $delivered)->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as rev')->first();
        $lastWeek = (clone $delivered)->whereBetween('created_at', [Carbon::now()->subWeek()->startOfWeek(), Carbon::now()->subWeek()->endOfWeek()])->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as rev')->first();

        $thisMonth = (clone $delivered)->whereMonth('created_at', Carbon::now()->month)->whereYear('created_at', Carbon::now()->year)->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as rev')->first();
        $lastMonth = (clone $delivered)->whereMonth('created_at', Carbon::now()->subMonth()->month)->whereYear('created_at', Carbon::now()->subMonth()->year)->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as rev')->first();

        $todayAov = (int) $today->cnt > 0 ? round((float) $today->rev / (int) $today->cnt, 2) : 0.0;
        $yesterdayAov = (int) $yesterday->cnt > 0 ? round((float) $yesterday->rev / (int) $yesterday->cnt, 2) : 0.0;
        $thisWeekAov = (int) $thisWeek->cnt > 0 ? round((float) $thisWeek->rev / (int) $thisWeek->cnt, 2) : 0.0;
        $lastWeekAov = (int) $lastWeek->cnt > 0 ? round((float) $lastWeek->rev / (int) $lastWeek->cnt, 2) : 0.0;
        $thisMonthAov = (int) $thisMonth->cnt > 0 ? round((float) $thisMonth->rev / (int) $thisMonth->cnt, 2) : 0.0;
        $lastMonthAov = (int) $lastMonth->cnt > 0 ? round((float) $lastMonth->rev / (int) $lastMonth->cnt, 2) : 0.0;

        $dailyTrend = $yesterdayAov > 0 ? round(($todayAov - $yesterdayAov) / $yesterdayAov * 100, 1) : null;
        $weeklyTrend = $lastWeekAov > 0 ? round(($thisWeekAov - $lastWeekAov) / $lastWeekAov * 100, 1) : null;
        $monthlyTrend = $lastMonthAov > 0 ? round(($thisMonthAov - $lastMonthAov) / $lastMonthAov * 100, 1) : null;

        $monthlySeries = [];
        $monthlyRows = (clone $delivered)
            ->selectRaw("DATE_TRUNC('month', created_at) as month, COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as rev")
            ->where('created_at', '>=', Carbon::now()->subMonths(11)->startOfMonth())
            ->groupByRaw("DATE_TRUNC('month', created_at)")
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->month)->toDateString());

        for ($i = 11; $i >= 0; $i--) {
            $m = Carbon::now()->subMonths($i)->startOfMonth();
            $key = $m->toDateString();
            $row = $monthlyRows->get($key);
            $cnt = $row ? (int) $row->cnt : 0;
            $rev = $row ? (float) $row->rev : 0.0;
            $monthlySeries[] = [
                'month' => $key,
                'label' => $m->format('M Y'),
                'aov' => $cnt > 0 ? round($rev / $cnt, 2) : 0.0,
                'orders' => $cnt,
                'revenue' => $rev,
            ];
        }

        $bands = [
            ['label' => 'Under ₱500', 'min' => 0, 'max' => 500, 'count' => 0],
            ['label' => '₱500–₱1,000', 'min' => 500, 'max' => 1000, 'count' => 0],
            ['label' => '₱1,000–₱2,000', 'min' => 1000, 'max' => 2000, 'count' => 0],
            ['label' => '₱2,000–₱5,000', 'min' => 2000, 'max' => 5000, 'count' => 0],
            ['label' => 'Over ₱5,000', 'min' => 5000, 'max' => PHP_FLOAT_MAX, 'count' => 0],
        ];

        $allDelivered = (clone $delivered)->select('total_amount')->get();
        foreach ($allDelivered as $o) {
            $amt = (float) $o->total_amount;
            foreach ($bands as &$b) {
                if ($amt >= $b['min'] && $amt < $b['max']) {
                    $b['count']++;
                    break;
                }
            }
        }
        unset($b);

        $totalForBands = array_sum(array_column($bands, 'count'));
        $distribution = array_map(fn ($b) => [
            'label' => $b['label'],
            'count' => $b['count'],
            'percentage' => $totalForBands > 0 ? round($b['count'] / $totalForBands * 100, 1) : 0.0,
        ], $bands);

        $median = 0.0;
        $sorted = $allDelivered->pluck('total_amount')->sort()->values();
        if ($sorted->count() > 0) {
            $mid = (int) floor($sorted->count() / 2);
            $median = $sorted->count() % 2 === 0
                ? round(((float) $sorted[$mid - 1] + (float) $sorted[$mid]) / 2, 2)
                : round((float) $sorted[$mid], 2);
        }

        return [
            'overall' => [
                'aov' => $overallAov,
                'median' => $median,
                'total_orders' => $overallCount,
                'total_revenue' => $overallRevenue,
            ],
            'periods' => [
                'today' => ['aov' => $todayAov, 'orders' => (int) $today->cnt, 'revenue' => (float) $today->rev, 'trend' => $dailyTrend],
                'this_week' => ['aov' => $thisWeekAov, 'orders' => (int) $thisWeek->cnt, 'revenue' => (float) $thisWeek->rev, 'trend' => $weeklyTrend],
                'this_month' => ['aov' => $thisMonthAov, 'orders' => (int) $thisMonth->cnt, 'revenue' => (float) $thisMonth->rev, 'trend' => $monthlyTrend],
            ],
            'monthly_series' => $monthlySeries,
            'distribution' => $distribution,
        ];
    }

    /**
     * Get return/refund rate metrics — overall and by period,
     * with monthly series and per-courier breakdown.
     *
     * @return array<string, mixed>
     */
    public function returnRefundRate(): array
    {
        $deliveredCount = Order::where('status', OrderStatus::DELIVERED)->count();
        $returnedCount = Order::where('status', OrderStatus::RETURNED)->count();
        $cancelledCount = Order::where('status', OrderStatus::CANCELLED)->count();

        $totalTerminal = $deliveredCount + $returnedCount + $cancelledCount;
        $returnRate = $totalTerminal > 0 ? round($returnedCount / $totalTerminal * 100, 1) : 0.0;
        $cancelRate = $totalTerminal > 0 ? round($cancelledCount / $totalTerminal * 100, 1) : 0.0;
        $combinedRate = round($returnRate + $cancelRate, 1);

        $returnedRevenue = (float) Order::where('status', OrderStatus::RETURNED)->sum('total_amount');
        $deliveredRevenue = (float) Order::where('status', OrderStatus::DELIVERED)->sum('total_amount');
        $refundRate = $deliveredRevenue > 0 ? round($returnedRevenue / ($deliveredRevenue + $returnedRevenue) * 100, 1) : 0.0;

        $todayReturned = Order::where('status', OrderStatus::RETURNED)->whereDate('returned_at', today())->count();
        $todayDelivered = Order::where('status', OrderStatus::DELIVERED)->whereDate('delivered_at', today())->count();
        $todayTotal = $todayReturned + $todayDelivered;
        $todayRate = $todayTotal > 0 ? round($todayReturned / $todayTotal * 100, 1) : 0.0;

        $thisWeekReturned = Order::where('status', OrderStatus::RETURNED)->whereBetween('returned_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])->count();
        $thisWeekDelivered = Order::where('status', OrderStatus::DELIVERED)->whereBetween('delivered_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])->count();
        $thisWeekTotal = $thisWeekReturned + $thisWeekDelivered;
        $thisWeekRate = $thisWeekTotal > 0 ? round($thisWeekReturned / $thisWeekTotal * 100, 1) : 0.0;

        $thisMonthReturned = Order::where('status', OrderStatus::RETURNED)->whereMonth('returned_at', Carbon::now()->month)->whereYear('returned_at', Carbon::now()->year)->count();
        $thisMonthDelivered = Order::where('status', OrderStatus::DELIVERED)->whereMonth('delivered_at', Carbon::now()->month)->whereYear('delivered_at', Carbon::now()->year)->count();
        $thisMonthTotal = $thisMonthReturned + $thisMonthDelivered;
        $thisMonthRate = $thisMonthTotal > 0 ? round($thisMonthReturned / $thisMonthTotal * 100, 1) : 0.0;

        $lastMonthReturned = Order::where('status', OrderStatus::RETURNED)->whereMonth('returned_at', Carbon::now()->subMonth()->month)->whereYear('returned_at', Carbon::now()->subMonth()->year)->count();
        $lastMonthDelivered = Order::where('status', OrderStatus::DELIVERED)->whereMonth('delivered_at', Carbon::now()->subMonth()->month)->whereYear('delivered_at', Carbon::now()->subMonth()->year)->count();
        $lastMonthTotal = $lastMonthReturned + $lastMonthDelivered;
        $lastMonthRate = $lastMonthTotal > 0 ? round($lastMonthReturned / $lastMonthTotal * 100, 1) : 0.0;

        $monthlyTrend = $lastMonthRate > 0 ? round($thisMonthRate - $lastMonthRate, 1) : null;

        $monthlySeries = [];
        $monthlyRows = Order::whereIn('status', [OrderStatus::DELIVERED, OrderStatus::RETURNED])
            ->selectRaw("
                DATE_TRUNC('month', created_at) as month,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as returned
            ", [OrderStatus::DELIVERED->value, OrderStatus::RETURNED->value])
            ->where('created_at', '>=', Carbon::now()->subMonths(11)->startOfMonth())
            ->groupByRaw("DATE_TRUNC('month', created_at)")
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->month)->toDateString());

        for ($i = 11; $i >= 0; $i--) {
            $m = Carbon::now()->subMonths($i)->startOfMonth();
            $key = $m->toDateString();
            $row = $monthlyRows->get($key);
            $delivered = $row ? (int) $row->delivered : 0;
            $returned = $row ? (int) $row->returned : 0;
            $total = $delivered + $returned;
            $monthlySeries[] = [
                'month' => $key,
                'label' => $m->format('M Y'),
                'delivered' => $delivered,
                'returned' => $returned,
                'rate' => $total > 0 ? round($returned / $total * 100, 1) : 0.0,
            ];
        }

        $byCourier = Order::whereIn('status', [OrderStatus::DELIVERED, OrderStatus::RETURNED])
            ->whereNotNull('courier_code')
            ->selectRaw('
                courier_code,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as returned
            ', [OrderStatus::DELIVERED->value, OrderStatus::RETURNED->value])
            ->groupBy('courier_code')
            ->orderByDesc('returned')
            ->get();

        $courierBreakdown = $byCourier->map(fn ($r) => [
            'courier' => $r->courier_code,
            'delivered' => (int) $r->delivered,
            'returned' => (int) $r->returned,
            'total' => (int) $r->delivered + (int) $r->returned,
            'rate' => ((int) $r->delivered + (int) $r->returned) > 0
                ? round((int) $r->returned / ((int) $r->delivered + (int) $r->returned) * 100, 1)
                : 0.0,
        ])->toArray();

        return [
            'overall' => [
                'return_rate' => $returnRate,
                'cancel_rate' => $cancelRate,
                'combined_rate' => $combinedRate,
                'refund_rate' => $refundRate,
                'delivered' => $deliveredCount,
                'returned' => $returnedCount,
                'cancelled' => $cancelledCount,
                'returned_revenue' => $returnedRevenue,
                'delivered_revenue' => $deliveredRevenue,
            ],
            'periods' => [
                'today' => ['rate' => $todayRate, 'returned' => $todayReturned, 'delivered' => $todayDelivered],
                'this_week' => ['rate' => $thisWeekRate, 'returned' => $thisWeekReturned, 'delivered' => $thisWeekDelivered],
                'this_month' => ['rate' => $thisMonthRate, 'returned' => $thisMonthReturned, 'delivered' => $thisMonthDelivered, 'trend' => $monthlyTrend],
            ],
            'monthly_series' => $monthlySeries,
            'by_courier' => $courierBreakdown,
        ];
    }

    /**
     * Generate an exportable sales report with optional date range filter.
     *
     * @param  string|null  $fromDate  Y-m-d start date (default: 30 days ago)
     * @param  string|null  $toDate  Y-m-d end date (default: today)
     * @return array<string, mixed>
     */
    public function exportSalesReport(?string $fromDate = null, ?string $toDate = null): array
    {
        $from = $fromDate ? Carbon::parse($fromDate)->startOfDay() : Carbon::now()->subDays(29)->startOfDay();
        $to = $toDate ? Carbon::parse($toDate)->endOfDay() : Carbon::now()->endOfDay();

        $orders = Order::whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        $delivered = $orders->where('status', OrderStatus::DELIVERED);
        $returned = $orders->where('status', OrderStatus::RETURNED);
        $cancelled = $orders->where('status', OrderStatus::CANCELLED);

        $grossRevenue = (float) $delivered->sum('total_amount');
        $returnedRevenue = (float) $returned->sum('total_amount');
        $netRevenue = $grossRevenue - $returnedRevenue;

        $totalOrders = $orders->count();
        $deliveredCount = $delivered->count();
        $returnedCount = $returned->count();
        $cancelledCount = $cancelled->count();

        $aov = $deliveredCount > 0 ? round($grossRevenue / $deliveredCount, 2) : 0.0;
        $returnRate = ($deliveredCount + $returnedCount) > 0
            ? round($returnedCount / ($deliveredCount + $returnedCount) * 100, 1)
            : 0.0;

        $summary = [
            'report_period' => "{$from->format('M j, Y')} – {$to->format('M j, Y')}",
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_orders' => $totalOrders,
            'delivered' => $deliveredCount,
            'returned' => $returnedCount,
            'cancelled' => $cancelledCount,
            'gross_revenue' => $grossRevenue,
            'returned_revenue' => $returnedRevenue,
            'net_revenue' => $netRevenue,
            'average_order_value' => $aov,
            'return_rate' => $returnRate,
        ];

        $statusBreakdown = [];
        $statusCounts = $orders->groupBy(fn ($o) => $o->status->value);
        foreach ($statusCounts as $status => $group) {
            $statusBreakdown[] = [
                'status' => $status,
                'count' => $group->count(),
                'revenue' => (float) $group->sum('total_amount'),
            ];
        }

        $topProducts = ShopOrderItem::query()
            ->join('orders', 'shop_order_items.order_id', '=', 'orders.id')
            ->where('orders.status', OrderStatus::DELIVERED)
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw('
                shop_order_items.product_id,
                shop_order_items.product_name,
                SUM(shop_order_items.quantity) as total_qty,
                SUM(shop_order_items.line_total) as total_revenue
            ')
            ->groupBy('shop_order_items.product_id', 'shop_order_items.product_name')
            ->orderByDesc('total_qty')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'product_name' => $r->product_name ?? 'Unknown',
                'quantity' => (int) $r->total_qty,
                'revenue' => (float) $r->total_revenue,
            ])
            ->toArray();

        $orderRows = $orders->map(fn ($o) => [
            'order_number' => $o->order_number,
            'date' => $o->created_at->format('Y-m-d'),
            'status' => $o->status->value,
            'customer_name' => $o->receiver_name ?? '',
            'product_name' => $o->product_name ?? '',
            'quantity' => (int) $o->quantity,
            'total_amount' => (float) $o->total_amount,
            'courier' => $o->courier_code ?? '',
            'source_channel' => $o->source_channel ?? '',
            'assigned_agent' => $o->assigned_agent_id ?? '',
        ])->toArray();

        return [
            'summary' => $summary,
            'status_breakdown' => $statusBreakdown,
            'top_products' => $topProducts,
            'orders' => $orderRows,
        ];
    }

    /**
     * Generate CSV content for the sales report download.
     *
     * @return string CSV content
     */
    public function exportSalesReportCsv(?string $fromDate = null, ?string $toDate = null): string
    {
        $report = $this->exportSalesReport($fromDate, $toDate);

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['SALES REPORT']);
        fputcsv($handle, ['Period', $report['summary']['report_period']]);
        fputcsv($handle, []);

        fputcsv($handle, ['SUMMARY']);
        fputcsv($handle, ['Metric', 'Value']);
        fputcsv($handle, ['Total Orders', $report['summary']['total_orders']]);
        fputcsv($handle, ['Delivered', $report['summary']['delivered']]);
        fputcsv($handle, ['Returned', $report['summary']['returned']]);
        fputcsv($handle, ['Cancelled', $report['summary']['cancelled']]);
        fputcsv($handle, ['Gross Revenue', $report['summary']['gross_revenue']]);
        fputcsv($handle, ['Returned Revenue', $report['summary']['returned_revenue']]);
        fputcsv($handle, ['Net Revenue', $report['summary']['net_revenue']]);
        fputcsv($handle, ['Average Order Value', $report['summary']['average_order_value']]);
        fputcsv($handle, ['Return Rate (%)', $report['summary']['return_rate']]);
        fputcsv($handle, []);

        fputcsv($handle, ['STATUS BREAKDOWN']);
        fputcsv($handle, ['Status', 'Count', 'Revenue']);
        foreach ($report['status_breakdown'] as $row) {
            fputcsv($handle, [$row['status'], $row['count'], $row['revenue']]);
        }
        fputcsv($handle, []);

        fputcsv($handle, ['TOP PRODUCTS']);
        fputcsv($handle, ['Product ID', 'Product Name', 'Quantity', 'Revenue']);
        foreach ($report['top_products'] as $row) {
            fputcsv($handle, [$row['product_id'], $row['product_name'], $row['quantity'], $row['revenue']]);
        }
        fputcsv($handle, []);

        fputcsv($handle, ['ORDER DETAILS']);
        fputcsv($handle, ['Order Number', 'Date', 'Status', 'Customer Name', 'Product Name', 'Quantity', 'Total Amount', 'Courier', 'Source Channel', 'Assigned Agent']);
        foreach ($report['orders'] as $row) {
            fputcsv($handle, [
                $row['order_number'],
                $row['date'],
                $row['status'],
                $row['customer_name'],
                $row['product_name'],
                $row['quantity'],
                $row['total_amount'],
                $row['courier'],
                $row['source_channel'],
                $row['assigned_agent'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Get predictive sales insights — revenue/order forecasts using
     * linear regression on historical data, day-of-week patterns,
     * growth projections, and anomaly detection.
     *
     * @param  int  $forecastDays  Number of days to forecast
     * @return array<string, mixed>
     */
    public function predictiveSalesInsights(int $forecastDays = 30): array
    {
        $historyDays = max($forecastDays * 3, 90);

        $dailyData = Order::where('status', OrderStatus::DELIVERED)
            ->where('created_at', '>=', Carbon::now()->subDays($historyDays)->startOfDay())
            ->selectRaw('DATE(created_at) as date, COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        $dates = [];
        $orders = [];
        $revenues = [];
        foreach ($dailyData as $d) {
            $dates[] = $d->date;
            $orders[] = (int) $d->orders;
            $revenues[] = (float) $d->revenue;
        }

        $n = count($revenues);
        $forecast = [];
        $growthRate = 0.0;
        $trendDirection = 'stable';

        if ($n >= 7) {
            $x = range(0, $n - 1);
            $sumX = array_sum($x);
            $sumY = array_sum($revenues);
            $sumXY = 0;
            $sumX2 = 0;
            foreach ($x as $i => $xi) {
                $sumXY += $xi * $revenues[$i];
                $sumX2 += $xi * $xi;
            }
            $denominator = ($n * $sumX2 - $sumX * $sumX);
            $slope = $denominator != 0 ? ($n * $sumXY - $sumX * $sumY) / $denominator : 0;
            $intercept = $n > 0 ? ($sumY - $slope * $sumX) / $n : 0;

            $avgRevenue = $n > 0 ? $sumY / $n : 0;
            $growthRate = $avgRevenue > 0 ? ($slope / $avgRevenue) * 100 : 0;

            if ($growthRate > 2) {
                $trendDirection = 'increasing';
            } elseif ($growthRate < -2) {
                $trendDirection = 'decreasing';
            }

            $xOrders = range(0, $n - 1);
            $sumXO = array_sum($xOrders);
            $sumYO = array_sum($orders);
            $sumXYO = 0;
            $sumX2O = 0;
            foreach ($xOrders as $i => $xi) {
                $sumXYO += $xi * $orders[$i];
                $sumX2O += $xi * $xi;
            }
            $denomO = ($n * $sumX2O - $sumXO * $sumXO);
            $slopeOrders = $denomO != 0 ? ($n * $sumXYO - $sumXO * $sumYO) / $denomO : 0;
            $interceptOrders = $n > 0 ? ($sumYO - $slopeOrders * $sumXO) / $n : 0;

            $dowFactors = array_fill(0, 7, 0.0);
            $dowCounts = array_fill(0, 7, 0);
            foreach ($dailyData as $d) {
                $dow = Carbon::parse($d->date)->dayOfWeek;
                $dowFactors[$dow] += (float) $d->revenue;
                $dowCounts[$dow]++;
            }
            $avgDailyRevenue = $n > 0 ? $sumY / $n : 0;
            foreach ($dowFactors as $i => $total) {
                $dowFactors[$i] = $dowCounts[$i] > 0
                    ? ($total / $dowCounts[$i]) / ($avgDailyRevenue > 0 ? $avgDailyRevenue : 1)
                    : 1.0;
            }

            for ($f = 1; $f <= $forecastDays; $f++) {
                $futureX = $n + $f - 1;
                $futureDate = Carbon::now()->addDays($f);
                $dow = $futureDate->dayOfWeek;

                $baseRevenue = $intercept + $slope * $futureX;
                $baseOrders = $interceptOrders + $slopeOrders * $futureX;

                $dowMultiplier = $dowFactors[$dow] > 0 ? $dowFactors[$dow] : 1.0;

                $predictedRevenue = max(0, $baseRevenue * $dowMultiplier);
                $predictedOrders = max(0, $baseOrders * $dowMultiplier);

                $confidence = max(0, min(100, 100 - ($f / $forecastDays * 40)));

                $forecast[] = [
                    'date' => $futureDate->toDateString(),
                    'day' => $futureDate->format('D'),
                    'predicted_revenue' => round($predictedRevenue, 2),
                    'predicted_orders' => (int) round($predictedOrders),
                    'confidence' => round($confidence, 1),
                ];
            }
        }

        $dowAverages = [];
        $dowData = array_fill(0, 7, ['orders' => 0, 'revenue' => 0.0, 'count' => 0]);
        foreach ($dailyData as $d) {
            $dow = Carbon::parse($d->date)->dayOfWeek;
            $dowData[$dow]['orders'] += (int) $d->orders;
            $dowData[$dow]['revenue'] += (float) $d->revenue;
            $dowData[$dow]['count']++;
        }
        $dowLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        for ($i = 0; $i < 7; $i++) {
            $cnt = $dowData[$i]['count'];
            $dowAverages[] = [
                'day' => $dowLabels[$i],
                'avg_orders' => $cnt > 0 ? round($dowData[$i]['orders'] / $cnt, 1) : 0.0,
                'avg_revenue' => $cnt > 0 ? round($dowData[$i]['revenue'] / $cnt, 2) : 0.0,
            ];
        }

        $bestDay = null;
        $worstDay = null;
        foreach ($dowAverages as $d) {
            if ($bestDay === null || $d['avg_revenue'] > $bestDay['avg_revenue']) {
                $bestDay = $d;
            }
            if ($worstDay === null || $d['avg_revenue'] < $worstDay['avg_revenue']) {
                $worstDay = $d;
            }
        }

        $anomalies = [];
        if ($n >= 14) {
            $recent14 = array_slice($revenues, -14);
            $mean14 = array_sum($recent14) / 14;
            $variance14 = 0;
            foreach ($recent14 as $r) {
                $variance14 += ($r - $mean14) ** 2;
            }
            $stdDev = sqrt($variance14 / 14);

            $checkStart = max(0, $n - 7);
            for ($i = $checkStart; $i < $n; $i++) {
                if ($stdDev > 0 && abs($revenues[$i] - $mean14) > $stdDev * 2) {
                    $anomalies[] = [
                        'date' => $dates[$i],
                        'revenue' => $revenues[$i],
                        'avg_revenue' => round($mean14, 2),
                        'deviation' => round(($revenues[$i] - $mean14) / $stdDev, 1),
                        'type' => $revenues[$i] > $mean14 ? 'spike' : 'drop',
                    ];
                }
            }
        }

        $totalForecastRevenue = array_sum(array_column($forecast, 'predicted_revenue'));
        $totalForecastOrders = array_sum(array_column($forecast, 'predicted_orders'));

        $last30Revenue = array_sum(array_slice($revenues, -min(30, $n)));
        $last30Orders = array_sum(array_slice($orders, -min(30, $n)));

        $projectedGrowth = $last30Revenue > 0
            ? round(($totalForecastRevenue - $last30Revenue) / $last30Revenue * 100, 1)
            : null;

        return [
            'forecast' => $forecast,
            'summary' => [
                'forecast_days' => $forecastDays,
                'history_days' => $historyDays,
                'total_forecast_revenue' => round($totalForecastRevenue, 2),
                'total_forecast_orders' => (int) $totalForecastOrders,
                'last_30d_revenue' => round($last30Revenue, 2),
                'last_30d_orders' => (int) $last30Orders,
                'projected_growth' => $projectedGrowth,
                'daily_growth_rate' => round($growthRate, 2),
                'trend_direction' => $trendDirection,
            ],
            'day_of_week' => [
                'averages' => $dowAverages,
                'best_day' => $bestDay,
                'worst_day' => $worstDay,
            ],
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Available widget catalog for the sales dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function availableWidgets(): array
    {
        return [
            ['key' => 'order_counts', 'label' => 'Order Counts', 'description' => 'Daily/weekly/monthly order counts', 'category' => 'overview', 'default_visible' => true, 'default_order' => 1],
            ['key' => 'revenue_totals', 'label' => 'Revenue Totals', 'description' => 'Gross and net revenue by period', 'category' => 'overview', 'default_visible' => true, 'default_order' => 2],
            ['key' => 'status_breakdown', 'label' => 'Order Status Breakdown', 'description' => 'Orders grouped by status', 'category' => 'overview', 'default_visible' => true, 'default_order' => 3],
            ['key' => 'top_products', 'label' => 'Top Products', 'description' => 'Best-selling products by quantity', 'category' => 'products', 'default_visible' => true, 'default_order' => 4],
            ['key' => 'sales_trends', 'label' => 'Sales Trends', 'description' => 'Order and revenue trends with moving averages', 'category' => 'trends', 'default_visible' => true, 'default_order' => 5],
            ['key' => 'revenue_by_source', 'label' => 'Revenue by Source', 'description' => 'Revenue breakdown by page and channel', 'category' => 'revenue', 'default_visible' => true, 'default_order' => 6],
            ['key' => 'revenue_by_payment_method', 'label' => 'Revenue by Payment Method', 'description' => 'Revenue breakdown by COD, cash, GCash, card', 'category' => 'revenue', 'default_visible' => true, 'default_order' => 7],
            ['key' => 'agent_leaderboard', 'label' => 'Agent Leaderboard', 'description' => 'Top agents by orders and revenue', 'category' => 'agents', 'default_visible' => true, 'default_order' => 8],
            ['key' => 'cohort_retention', 'label' => 'Cohort Retention', 'description' => 'Customer retention by first-order month', 'category' => 'customers', 'default_visible' => false, 'default_order' => 9],
            ['key' => 'average_order_value', 'label' => 'Average Order Value', 'description' => 'AOV with distribution and median', 'category' => 'revenue', 'default_visible' => false, 'default_order' => 10],
            ['key' => 'return_refund_rate', 'label' => 'Return/Refund Rate', 'description' => 'Return and cancellation rates', 'category' => 'overview', 'default_visible' => false, 'default_order' => 11],
            ['key' => 'predictive_insights', 'label' => 'Predictive Insights', 'description' => 'Revenue forecasts and anomaly detection', 'category' => 'trends', 'default_visible' => false, 'default_order' => 12],
        ];
    }

    /**
     * Get the user's dashboard widget configuration.
     *
     * @return array<string, mixed>
     */
    public function getWidgetConfig(int $userId, string $dashboard = 'sales'): array
    {
        $configs = DashboardWidgetConfig::where('user_id', $userId)
            ->where('dashboard', $dashboard)
            ->get()
            ->keyBy('widget_key');

        $available = self::availableWidgets();
        $widgets = [];

        foreach ($available as $widget) {
            $config = $configs->get($widget['key']);
            $widgets[] = [
                'key' => $widget['key'],
                'label' => $widget['label'],
                'description' => $widget['description'],
                'category' => $widget['category'],
                'is_visible' => $config?->is_visible ?? $widget['default_visible'],
                'sort_order' => $config?->sort_order ?? $widget['default_order'],
                'settings' => $config?->settings ?? [],
            ];
        }

        usort($widgets, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        $visible = array_values(array_filter($widgets, fn ($w) => $w['is_visible']));
        $hidden = array_values(array_filter($widgets, fn ($w) => ! $w['is_visible']));

        return [
            'widgets' => $widgets,
            'visible_widgets' => $visible,
            'hidden_widgets' => $hidden,
            'dashboard' => $dashboard,
        ];
    }

    /**
     * Save the user's dashboard widget configuration.
     *
     * @param  array  $widgetConfigs  Array of ['key' => ..., 'is_visible' => ..., 'sort_order' => ..., 'settings' => ...]
     * @return array<string, mixed>
     */
    public function saveWidgetConfig(int $userId, array $widgetConfigs, string $dashboard = 'sales'): array
    {
        $availableKeys = array_column(self::availableWidgets(), 'key');

        foreach ($widgetConfigs as $config) {
            $key = $config['key'] ?? null;
            if (! $key || ! in_array($key, $availableKeys)) {
                continue;
            }

            DashboardWidgetConfig::updateOrCreate(
                [
                    'user_id' => $userId,
                    'dashboard' => $dashboard,
                    'widget_key' => $key,
                ],
                [
                    'is_visible' => $config['is_visible'] ?? true,
                    'sort_order' => $config['sort_order'] ?? 0,
                    'settings' => $config['settings'] ?? null,
                ]
            );
        }

        return $this->getWidgetConfig($userId, $dashboard);
    }

    /**
     * Reset the user's dashboard widget configuration to defaults.
     *
     * @return array<string, mixed>
     */
    public function resetWidgetConfig(int $userId, string $dashboard = 'sales'): array
    {
        DashboardWidgetConfig::where('user_id', $userId)
            ->where('dashboard', $dashboard)
            ->delete();

        return $this->getWidgetConfig($userId, $dashboard);
    }

    /**
     * List scheduled sales reports for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listScheduledReports(int $userId): array
    {
        return ScheduledSalesReport::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => $this->formatScheduledReport($r))
            ->toArray();
    }

    /**
     * Create a scheduled sales report.
     *
     * @return array<string, mixed>
     */
    public function createScheduledReport(int $userId, array $data): array
    {
        $report = ScheduledSalesReport::create([
            'user_id' => $userId,
            'name' => $data['name'] ?? 'Untitled Report',
            'frequency' => $data['frequency'] ?? 'weekly',
            'send_at' => $data['send_at'] ?? '08:00',
            'day_of_week' => $data['day_of_week'] ?? null,
            'day_of_month' => $data['day_of_month'] ?? null,
            'format' => $data['format'] ?? 'csv',
            'lookback_days' => $data['lookback_days'] ?? 7,
            'recipients' => $data['recipients'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'next_run_at' => $this->calculateNextRunAt(
                $data['frequency'] ?? 'weekly',
                $data['send_at'] ?? '08:00',
                $data['day_of_week'] ?? null,
                $data['day_of_month'] ?? null,
            ),
        ]);

        return $this->formatScheduledReport($report);
    }

    /**
     * Update a scheduled sales report.
     *
     * @return array<string, mixed>|null
     */
    public function updateScheduledReport(int $reportId, int $userId, array $data): ?array
    {
        $report = ScheduledSalesReport::where('id', $reportId)
            ->where('user_id', $userId)
            ->first();

        if (! $report) {
            return null;
        }

        $updates = [];
        foreach (['name', 'frequency', 'send_at', 'day_of_week', 'day_of_month', 'format', 'lookback_days', 'recipients', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (isset($updates['frequency']) || isset($updates['send_at']) || isset($updates['day_of_week']) || isset($updates['day_of_month'])) {
            $updates['next_run_at'] = $this->calculateNextRunAt(
                $updates['frequency'] ?? $report->frequency,
                $updates['send_at'] ?? $report->send_at,
                $updates['day_of_week'] ?? $report->day_of_week,
                $updates['day_of_month'] ?? $report->day_of_month,
            );
        }

        $report->update($updates);

        return $this->formatScheduledReport($report->fresh());
    }

    /**
     * Delete a scheduled sales report.
     */
    public function deleteScheduledReport(int $reportId, int $userId): bool
    {
        return ScheduledSalesReport::where('id', $reportId)
            ->where('user_id', $userId)
            ->delete() > 0;
    }

    /**
     * Calculate the next run time for a scheduled report.
     *
     * @param  string  $sendAt  H:i format
     */
    private function calculateNextRunAt(string $frequency, string $sendAt, ?string $dayOfWeek, ?int $dayOfMonth): Carbon
    {
        $now = Carbon::now();

        return match ($frequency) {
            'daily' => $now->copy()->addDay()->setTimeFromTimeString($sendAt),
            'weekly' => $this->nextWeeklyRunAt($dayOfWeek ?? 'mon', $sendAt, $now),
            'monthly' => $this->nextMonthlyRunAt($dayOfMonth ?? 1, $sendAt, $now),
            default => $now->copy()->addWeek()->setTimeFromTimeString($sendAt),
        };
    }

    private function nextWeeklyRunAt(string $dayOfWeek, string $sendAt, Carbon $now): Carbon
    {
        $dayMap = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $targetDow = $dayMap[strtolower($dayOfWeek)] ?? 1;
        $daysUntil = ($targetDow - $now->dayOfWeek + 7) % 7;
        if ($daysUntil === 0) {
            $daysUntil = 7;
        }

        return $now->copy()->addDays($daysUntil)->setTimeFromTimeString($sendAt);
    }

    private function nextMonthlyRunAt(int $dayOfMonth, string $sendAt, Carbon $now): Carbon
    {
        $next = $now->copy()->addMonth();
        $lastDay = (int) $next->format('t');

        return $next->setDay(min($dayOfMonth, $lastDay))->setTimeFromTimeString($sendAt);
    }

    /**
     * Format a scheduled report for API response.
     *
     * @return array<string, mixed>
     */
    private function formatScheduledReport(ScheduledSalesReport $report): array
    {
        return [
            'id' => $report->id,
            'name' => $report->name,
            'frequency' => $report->frequency,
            'send_at' => $report->send_at,
            'day_of_week' => $report->day_of_week,
            'day_of_month' => $report->day_of_month,
            'format' => $report->format,
            'lookback_days' => $report->lookback_days,
            'recipients' => $report->recipients ?? [],
            'is_active' => $report->is_active,
            'last_run_at' => $report->last_run_at?->toIso8601String(),
            'next_run_at' => $report->next_run_at?->toIso8601String(),
            'created_at' => $report->created_at->toIso8601String(),
        ];
    }
}
