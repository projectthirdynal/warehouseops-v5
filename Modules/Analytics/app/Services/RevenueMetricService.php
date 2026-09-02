<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;

class RevenueMetricService
{
    private const CACHE_TTL = 300;

    public function revenueSummary(): array
    {
        return Cache::remember('revenue:summary', self::CACHE_TTL, function () {
            $today = today();
            $startOfWeek = Carbon::now()->startOfWeek();
            $endOfWeek = Carbon::now()->endOfWeek();
            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();

            $startOfLastWeek = Carbon::now()->subWeek()->startOfWeek();
            $endOfLastWeek = Carbon::now()->subWeek()->endOfWeek();
            $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
            $endOfLastMonth = Carbon::now()->subMonth()->endOfMonth();

            $todayGross = $this->grossRevenue($today, $today);
            $todayNet = $this->netRevenue($today, $today);
            $weekGross = $this->grossRevenue($startOfWeek, $endOfWeek);
            $weekNet = $this->netRevenue($startOfWeek, $endOfWeek);
            $monthGross = $this->grossRevenue($startOfMonth, $endOfMonth);
            $monthNet = $this->netRevenue($startOfMonth, $endOfMonth);

            $lastWeekGross = $this->grossRevenue($startOfLastWeek, $endOfLastWeek);
            $lastMonthGross = $this->grossRevenue($startOfLastMonth, $endOfLastMonth);

            $todayCollected = $this->collectedRevenue($today, $today);
            $weekCollected = $this->collectedRevenue($startOfWeek, $endOfWeek);
            $monthCollected = $this->collectedRevenue($startOfMonth, $endOfMonth);

            return [
                'today_gross' => $todayGross,
                'today_net' => $todayNet,
                'today_collected' => $todayCollected,
                'this_week_gross' => $weekGross,
                'this_week_net' => $weekNet,
                'this_week_collected' => $weekCollected,
                'this_month_gross' => $monthGross,
                'this_month_net' => $monthNet,
                'this_month_collected' => $monthCollected,
                'today_trend' => $this->trend($todayGross, $this->grossRevenue(today()->subDay(), today()->subDay())),
                'week_trend' => $this->trend($weekGross, $lastWeekGross),
                'month_trend' => $this->trend($monthGross, $lastMonthGross),
            ];
        });
    }

    public function grossRevenue(Carbon $from, Carbon $to): float
    {
        return (float) Order::where('status', OrderStatus::DELIVERED)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->sum('total_amount');
    }

    public function netRevenue(Carbon $from, Carbon $to): float
    {
        $gross = $this->grossRevenue($from, $to);

        $refunds = (float) Order::where('status', OrderStatus::RETURNED)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->sum('total_amount');

        return $gross - $refunds;
    }

    public function collectedRevenue(Carbon $from, Carbon $to): float
    {
        return (float) Invoice::whereIn('status', ['PAID', 'PARTIAL'])
            ->whereBetween('updated_at', [$from->startOfDay(), $to->endOfDay()])
            ->sum('amount_paid');
    }

    private function trend(float $current, float $previous): ?int
    {
        if ($previous == 0) {
            return null;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }
}
