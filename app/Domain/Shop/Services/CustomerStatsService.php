<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class CustomerStatsService
{
    /**
     * Recalculate order counters, success rate, revenue, and last-order metadata for a customer.
     */
    public function recalculate(Customer $customer): void
    {
        $stats = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->selectRaw(
                'count(*) as total_orders, '
                . 'sum(case when status = ? then 1 else 0 end) as successful_orders, '
                . 'sum(case when status = ? then 1 else 0 end) as returned_orders, '
                . 'sum(case when status = ? then 1 else 0 end) as cancelled_orders, '
                . 'coalesce(sum(total_amount), 0) as total_revenue, '
                . 'max(created_at) as last_order_at',
                [OrderStatus::DELIVERED->value, OrderStatus::RETURNED->value, OrderStatus::CANCELLED->value]
            )
            ->first();

        $totalOrders = (int) ($stats?->total_orders ?? 0);
        $successfulOrders = (int) ($stats?->successful_orders ?? 0);
        $returnedOrders = (int) ($stats?->returned_orders ?? 0);

        $successRate = $totalOrders > 0
            ? round(($successfulOrders / $totalOrders) * 100, 2)
            : 0.00;

        $totalRevenue = (float) ($stats?->total_revenue ?? 0);
        $averageOrderValue = $totalOrders > 0
            ? round($totalRevenue / $totalOrders, 2)
            : 0.00;

        $lastOrder = $customer->orders()
            ->latest('created_at')
            ->first(['created_at', 'facebook_page_id']);

        $customer->forceFill([
            'total_orders' => $totalOrders,
            'successful_orders' => $successfulOrders,
            'returned_orders' => $returnedOrders,
            'success_rate' => $successRate,
            'total_revenue' => $totalRevenue,
            'average_order_value' => $averageOrderValue,
            'last_order_date' => $lastOrder?->created_at,
            'last_page_ordered_from' => $lastOrder?->facebook_page_id,
        ])->save();
    }
}
