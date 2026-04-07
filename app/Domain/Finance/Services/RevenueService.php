<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Carbon;

class RevenueService
{
    /**
     * Record revenue from a delivered order.
     */
    public function recordSale(Order $order): void
    {
        FinancialTransaction::create([
            'type'             => 'REVENUE',
            'amount'           => $order->total_amount,
            'reference_type'   => Order::class,
            'reference_id'     => $order->id,
            'description'      => "Sale: {$order->order_number} — {$order->product?->name}",
            'transaction_date' => today(),
        ]);

        if ($order->shipping_cost > 0) {
            FinancialTransaction::create([
                'type'             => 'SHIPPING_COST',
                'amount'           => -$order->shipping_cost,
                'reference_type'   => Order::class,
                'reference_id'     => $order->id,
                'description'      => "Shipping cost: {$order->order_number}",
                'transaction_date' => today(),
            ]);
        }
    }

    /**
     * Record a refund/return.
     */
    public function recordReturn(Order $order): void
    {
        FinancialTransaction::create([
            'type'             => 'REFUND',
            'amount'           => -$order->total_amount,
            'reference_type'   => Order::class,
            'reference_id'     => $order->id,
            'description'      => "Return: {$order->order_number}",
            'transaction_date' => today(),
        ]);
    }

    /**
     * Get revenue summary for a date range.
     */
    public function getSummary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->startOfMonth();
        $to = $to ?? now()->endOfDay();

        $transactions = FinancialTransaction::whereBetween('transaction_date', [$from, $to]);

        $revenue   = (float) $transactions->clone()->where('type', 'REVENUE')->sum('amount');
        $shipping  = (float) abs($transactions->clone()->where('type', 'SHIPPING_COST')->sum('amount'));
        $commissions = (float) abs($transactions->clone()->where('type', 'COMMISSION')->sum('amount'));
        $refunds   = (float) abs($transactions->clone()->where('type', 'REFUND')->sum('amount'));

        // COGS from orders
        $deliveredOrders = Order::where('status', OrderStatus::DELIVERED)
            ->whereBetween('delivered_at', [$from, $to])
            ->with('product')
            ->get();

        $cogs = $deliveredOrders->sum(function ($order) {
            $cost = $order->product ? (float) $order->product->cost_price : 0;
            return $cost * $order->quantity;
        });

        $grossRevenue = $revenue;
        $netRevenue = $revenue - $refunds;
        $grossProfit = $netRevenue - $cogs;
        $netProfit = $grossProfit - $shipping - $commissions;

        return [
            'period'         => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'gross_revenue'  => $grossRevenue,
            'refunds'        => $refunds,
            'net_revenue'    => $netRevenue,
            'cogs'           => $cogs,
            'gross_profit'   => $grossProfit,
            'shipping_costs' => $shipping,
            'commissions'    => $commissions,
            'net_profit'     => $netProfit,
            'margin'         => $netRevenue > 0 ? round(($netProfit / $netRevenue) * 100, 1) : 0,
            'orders_delivered' => $deliveredOrders->count(),
            'orders_returned'  => Order::where('status', OrderStatus::RETURNED)->whereBetween('returned_at', [$from, $to])->count(),
        ];
    }

    /**
     * Daily revenue breakdown for charts.
     */
    public function getDailyRevenue(int $days = 30): array
    {
        $from = now()->subDays($days)->startOfDay();

        return FinancialTransaction::where('type', 'REVENUE')
            ->where('transaction_date', '>=', $from)
            ->selectRaw("transaction_date, SUM(amount) as total")
            ->groupBy('transaction_date')
            ->orderBy('transaction_date')
            ->get()
            ->map(fn ($row) => [
                'date'  => $row->transaction_date->format('M d'),
                'total' => (float) $row->total,
            ])
            ->toArray();
    }
}
