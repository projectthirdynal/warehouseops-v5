<?php

namespace App\Services;

use App\Domain\Finance\Models\AgentCommission;
use App\Domain\Finance\Models\CodSettlement;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Models\Customer;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Support\Carbon;

class ReportService
{
    /**
     * Sales report — orders with revenue breakdown.
     */
    public function salesReport(Carbon $from, Carbon $to): array
    {
        $orders = Order::whereBetween('created_at', [$from, $to])
            ->with(['product', 'agent'])
            ->get();

        $delivered = $orders->where('status', OrderStatus::DELIVERED);
        $returned = $orders->where('status', OrderStatus::RETURNED);

        return [
            'period'           => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_orders'     => $orders->count(),
            'delivered'        => $delivered->count(),
            'returned'         => $returned->count(),
            'cancelled'        => $orders->where('status', OrderStatus::CANCELLED)->count(),
            'pending'          => $orders->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::RETURNED, OrderStatus::CANCELLED, OrderStatus::QA_REJECTED])->count(),
            'gross_revenue'    => (float) $delivered->sum('total_amount'),
            'refunds'          => (float) $returned->sum('total_amount'),
            'net_revenue'      => (float) $delivered->sum('total_amount') - (float) $returned->sum('total_amount'),
            'avg_order_value'  => $delivered->count() > 0 ? round($delivered->avg('total_amount'), 2) : 0,
            'delivery_rate'    => $orders->count() > 0 ? round(($delivered->count() / $orders->count()) * 100, 1) : 0,
            'rows'             => $orders->map(fn ($o) => [
                'order_number'  => $o->order_number,
                'date'          => $o->created_at->format('Y-m-d'),
                'product'       => $o->product?->name ?? 'N/A',
                'agent'         => $o->agent?->name ?? 'N/A',
                'status'        => $o->status->value,
                'amount'        => (float) $o->total_amount,
                'cod'           => (float) $o->cod_amount,
                'courier'       => $o->courier_code,
            ])->toArray(),
        ];
    }

    /**
     * Agent performance report.
     */
    public function agentReport(Carbon $from, Carbon $to): array
    {
        $agents = User::where('role', 'agent')->where('is_active', true)->get();

        $rows = $agents->map(function ($agent) use ($from, $to) {
            $orders = Order::where('assigned_agent_id', $agent->id)
                ->whereBetween('created_at', [$from, $to]);

            $delivered = (clone $orders)->where('status', OrderStatus::DELIVERED)->count();
            $returned = (clone $orders)->where('status', OrderStatus::RETURNED)->count();
            $total = (clone $orders)->count();
            $revenue = (float) (clone $orders)->where('status', OrderStatus::DELIVERED)->sum('total_amount');

            $commissions = AgentCommission::where('agent_id', $agent->id)
                ->whereBetween('earned_at', [$from, $to])
                ->sum('commission_amount');

            return [
                'agent_id'       => $agent->id,
                'name'           => $agent->name,
                'total_orders'   => $total,
                'delivered'      => $delivered,
                'returned'       => $returned,
                'delivery_rate'  => $total > 0 ? round(($delivered / $total) * 100, 1) : 0,
                'revenue'        => $revenue,
                'commission'     => (float) $commissions,
            ];
        })->sortByDesc('revenue')->values()->toArray();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'rows'   => $rows,
        ];
    }

    /**
     * Courier comparison report.
     */
    public function courierReport(Carbon $from, Carbon $to): array
    {
        $couriers = ['FLASH', 'JNT', 'MANUAL'];
        $rows = [];

        foreach ($couriers as $code) {
            $waybills = Waybill::where('courier_provider', $code)
                ->whereBetween('created_at', [$from, $to]);

            $total = (clone $waybills)->count();
            if ($total === 0) continue;

            $delivered = (clone $waybills)->where('status', 'DELIVERED')->count();
            $returned = (clone $waybills)->where('status', 'RETURNED')->count();

            $avgDeliveryDays = (clone $waybills)->where('status', 'DELIVERED')
                ->whereNotNull('delivered_at')
                ->whereNotNull('dispatched_at')
                ->get()
                ->avg(fn ($w) => $w->dispatched_at ? $w->delivered_at->diffInDays($w->dispatched_at) : null);

            $codTotal = (float) (clone $waybills)->where('status', 'DELIVERED')->sum('cod_amount');
            $shippingTotal = (float) (clone $waybills)->sum('shipping_cost');

            $rows[] = [
                'courier'          => $code,
                'total_shipments'  => $total,
                'delivered'        => $delivered,
                'returned'         => $returned,
                'delivery_rate'    => round(($delivered / $total) * 100, 1),
                'return_rate'      => round(($returned / $total) * 100, 1),
                'avg_delivery_days' => $avgDeliveryDays ? round($avgDeliveryDays, 1) : null,
                'cod_collected'    => $codTotal,
                'shipping_costs'   => $shippingTotal,
            ];
        }

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'rows'   => $rows,
        ];
    }

    /**
     * Product P&L report.
     */
    public function productReport(Carbon $from, Carbon $to): array
    {
        $products = Product::with('stock')->get();

        $rows = $products->map(function ($product) use ($from, $to) {
            $orders = Order::where('product_id', $product->id)
                ->whereBetween('created_at', [$from, $to]);

            $delivered = (clone $orders)->where('status', OrderStatus::DELIVERED);
            $returned = (clone $orders)->where('status', OrderStatus::RETURNED);
            $deliveredCount = $delivered->count();
            $revenue = (float) $delivered->sum('total_amount');
            $cogs = $deliveredCount * (float) $product->cost_price;
            $grossProfit = $revenue - $cogs;

            return [
                'product_id'    => $product->id,
                'sku'           => $product->sku,
                'name'          => $product->name,
                'brand'         => $product->brand,
                'total_orders'  => (clone $orders)->count(),
                'delivered'     => $deliveredCount,
                'returned'      => $returned->count(),
                'revenue'       => $revenue,
                'cogs'          => $cogs,
                'gross_profit'  => $grossProfit,
                'margin'        => $revenue > 0 ? round(($grossProfit / $revenue) * 100, 1) : 0,
                'current_stock' => $product->stock?->current_stock ?? 0,
            ];
        })->sortByDesc('revenue')->values()->toArray();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'rows'   => $rows,
        ];
    }

    /**
     * Customer analysis report.
     */
    public function customerReport(Carbon $from, Carbon $to): array
    {
        $customers = Customer::orderBy('total_orders', 'desc')->limit(100)->get();

        $rows = $customers->map(function ($customer) use ($from, $to) {
            $recentOrders = Order::where('customer_id', $customer->id)
                ->whereBetween('created_at', [$from, $to])
                ->count();

            return [
                'customer_id'      => $customer->id,
                'name'             => $customer->name,
                'phone'            => $customer->phone,
                'total_orders'     => $customer->total_orders,
                'successful'       => $customer->successful_orders,
                'returned'         => $customer->returned_orders,
                'success_rate'     => (float) $customer->success_rate,
                'total_revenue'    => (float) $customer->total_revenue,
                'risk_level'       => $customer->risk_level,
                'recent_orders'    => $recentOrders,
            ];
        })->toArray();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'rows'   => $rows,
        ];
    }
}
