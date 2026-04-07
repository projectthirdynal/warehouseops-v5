<?php

namespace App\Http\Controllers;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Services\OrderFulfillmentService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderController extends Controller
{
    public function __construct(private OrderFulfillmentService $fulfillment) {}

    public function index(Request $request)
    {
        $query = Order::with(['product', 'agent', 'customer', 'waybill']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'ILIKE', "%{$search}%")
                  ->orWhere('receiver_name', 'ILIKE', "%{$search}%")
                  ->orWhere('receiver_phone', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('courier')) {
            $query->where('courier_code', $request->courier);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'      => Order::count(),
            'pending'    => Order::where('status', OrderStatus::PENDING)->count(),
            'qa_pending' => Order::where('status', OrderStatus::QA_PENDING)->count(),
            'processing' => Order::where('status', OrderStatus::PROCESSING)->count(),
            'dispatched' => Order::where('status', OrderStatus::DISPATCHED)->count(),
            'delivered'  => Order::where('status', OrderStatus::DELIVERED)->count(),
            'returned'   => Order::where('status', OrderStatus::RETURNED)->count(),
        ];

        return Inertia::render('Orders/Index', [
            'orders'  => $orders,
            'stats'   => $stats,
            'filters' => $request->only(['search', 'status', 'courier']),
        ]);
    }

    public function show(Order $order)
    {
        $order->load(['product', 'variant', 'agent', 'customer', 'lead', 'waybill.trackingHistory']);

        return Inertia::render('Orders/Show', [
            'order' => $order,
        ]);
    }

    /**
     * QA approve an order.
     */
    public function approve(Order $order)
    {
        if ($order->status !== OrderStatus::QA_PENDING) {
            return back()->with('error', 'Only QA_PENDING orders can be approved.');
        }

        $this->fulfillment->approve($order, auth()->id());

        return back()->with('success', "Order {$order->order_number} approved and submitted to courier.");
    }

    /**
     * QA reject an order.
     */
    public function reject(Request $request, Order $order)
    {
        if ($order->status !== OrderStatus::QA_PENDING) {
            return back()->with('error', 'Only QA_PENDING orders can be rejected.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->fulfillment->reject($order, $validated['reason'], auth()->id());

        return back()->with('success', "Order {$order->order_number} rejected.");
    }

    /**
     * Cancel an order.
     */
    public function cancel(Request $request, Order $order)
    {
        if ($order->status->isTerminal()) {
            return back()->with('error', 'Cannot cancel a completed order.');
        }

        $this->fulfillment->cancel($order, $request->input('reason'));

        return back()->with('success', "Order {$order->order_number} cancelled.");
    }

    /**
     * Retry courier submission for PROCESSING orders.
     */
    public function retryCourier(Order $order)
    {
        if ($order->status !== OrderStatus::PROCESSING) {
            return back()->with('error', 'Only PROCESSING orders can be retried.');
        }

        $this->fulfillment->submitToCourier($order);

        return back()->with('success', "Courier submission retried for {$order->order_number}.");
    }
}
