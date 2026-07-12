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
            ->selectRaw(<<-'SQL'
                count(*) as total_orders,
                count(*) filter (where status = ?) as successful_orders,
                count(*) filter (where status = ?) as returned_orders,
                count(*) filter (where status = ?) as cancelled_orders,
                coalesce(sum(total_amount), 0) as total_revenue,
                max(created_at) as last_order_at
            SQL, [OrderStatus::DELIVERED->value, OrderStatus::RETURNED->value, OrderStatus::CANCELLED->value])
            ->first();

        $totalOrders = (int) ($stats?->total_orders ?? 0);
        $successfulOrders = (int) ($stats?->successful_orders ?? 0);
        $returnedOrders = (int) ($stats?->returned_orders ?? 0);

        $successRate = $totalOrders > 0
            ? round(($successfulOrders / $totalOrders) * 100, 2)
            : 0.00;

        $lastOrder = $customer->orders()
            ->latest('created_at')
            ->first(['created_at', 'facebook_page_id']);

        $customer->forceFill([
            'total_orders' => $totalOrders,
            'successful_orders' => $successfulOrders,
            'returned_orders' => $returnedOrders,
            'success_rate' => $successRate,
            'total_revenue' => $stats?->total_revenue ?? 0,
            'last_order_date' => $lastOrder?->created_at,
            'last_page_ordered_from' => $lastOrder?->facebook_page_id,
        ])->save();
    }
}
