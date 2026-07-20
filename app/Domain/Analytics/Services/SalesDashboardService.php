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
}
