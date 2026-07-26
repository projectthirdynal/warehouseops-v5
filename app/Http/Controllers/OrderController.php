<?php

namespace App\Http\Controllers;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Services\OrderFulfillmentService;
use App\Domain\Shop\Models\OrderRemark;
use App\Models\User;
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
        $order->load([
            'product',
            'variant',
            'agent',
            'customer',
            'lead',
            'waybill.trackingHistory',
            'shopItems.product',
            'shopItems.variant',
        ]);

        $customerOrders = $order->customer_id
            ? Order::query()
                ->with('shopItems:id,order_id,product_name,quantity,line_total')
                ->where('customer_id', $order->customer_id)
                ->where('id', '!=', $order->id)
                ->latest()
                ->limit(5)
                ->get(['id', 'order_number', 'status', 'total_amount', 'cod_amount', 'created_at'])
            : collect();

        return Inertia::render('Orders/Show', [
            'order' => $order,
            'duplicate_warnings' => $this->duplicateWarnings($order),
            'customer_orders' => $customerOrders,
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

    public function resolveDuplicateWarning(Request $request, Order $order, OrderRemark $remark)
    {
        abort_unless(
            $remark->order_id === $order->id && $remark->type === 'duplicate_warning',
            404
        );

        $validated = $request->validate([
            'decision' => ['required', 'in:continue,use_existing,cancel_new'],
        ]);

        $metadata = $remark->metadata ?? [];
        $duplicateOrderId = isset($metadata['duplicate_order_id'])
            ? (int) $metadata['duplicate_order_id']
            : null;
        $duplicateOrder = $duplicateOrderId
            ? Order::query()->select(['id', 'order_number'])->find($duplicateOrderId)
            : null;

        if ($validated['decision'] === 'use_existing' && ! $duplicateOrder) {
            return back()->with('error', 'The referenced existing order is no longer available.');
        }

        $shouldCancelNewOrder = in_array($validated['decision'], ['use_existing', 'cancel_new'], true);
        $cancelledNewOrder = false;

        if ($shouldCancelNewOrder && ! $order->status->isTerminal()) {
            $reason = $validated['decision'] === 'use_existing'
                ? "Duplicate order reviewed. Kept existing order {$duplicateOrder?->order_number}."
                : 'Duplicate order reviewed and cancelled.';

            $this->fulfillment->cancel($order, $reason);
            $order->refresh();
            $cancelledNewOrder = true;
        }

        $remark->update([
            'metadata' => array_merge($metadata, [
                'resolution_status' => $validated['decision'],
                'resolved_by' => $request->user()?->id,
                'resolved_at' => now()->toIso8601String(),
                'linked_order_id' => $validated['decision'] === 'use_existing'
                    ? $duplicateOrder?->id
                    : null,
            ]),
        ]);

        $message = match ($validated['decision']) {
            'continue' => "Duplicate warning reviewed. {$order->order_number} remains active.",
            'use_existing' => $cancelledNewOrder
                ? "Duplicate warning resolved. Existing order {$duplicateOrder?->order_number} kept and {$order->order_number} cancelled."
                : "Duplicate warning resolved. Existing order {$duplicateOrder?->order_number} marked as the kept order.",
            'cancel_new' => $cancelledNewOrder
                ? "Duplicate warning resolved. {$order->order_number} cancelled."
                : "Duplicate warning resolved. {$order->order_number} marked as cancelled during duplicate review.",
        };

        return back()->with('success', $message);
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

    private function duplicateWarnings(Order $order): array
    {
        $remarks = OrderRemark::query()
            ->where('order_id', $order->id)
            ->where('type', 'duplicate_warning')
            ->latest()
            ->get();

        $duplicateOrderIds = $remarks
            ->pluck('metadata.duplicate_order_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $resolverIds = $remarks
            ->pluck('metadata.resolved_by')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $duplicateOrders = Order::query()
            ->select(['id', 'order_number', 'status', 'created_at', 'receiver_name'])
            ->whereIn('id', $duplicateOrderIds)
            ->get()
            ->keyBy('id');

        $resolvers = User::query()
            ->select(['id', 'name'])
            ->whereIn('id', $resolverIds)
            ->get()
            ->keyBy('id');

        return $remarks->map(function (OrderRemark $remark) use ($duplicateOrders, $resolvers) {
            $metadata = $remark->metadata ?? [];
            $duplicateOrder = isset($metadata['duplicate_order_id'])
                ? $duplicateOrders->get((int) $metadata['duplicate_order_id'])
                : null;
            $resolvedBy = isset($metadata['resolved_by'])
                ? $resolvers->get((int) $metadata['resolved_by'])
                : null;

            return [
                'id' => $remark->id,
                'body' => $remark->body,
                'created_at' => optional($remark->created_at)?->toIso8601String(),
                'duplicate_order_id' => $metadata['duplicate_order_id'] ?? null,
                'duplicate_order_number' => $metadata['duplicate_order_number'] ?? $duplicateOrder?->order_number,
                'duplicate_order' => $duplicateOrder ? [
                    'id' => $duplicateOrder->id,
                    'order_number' => $duplicateOrder->order_number,
                    'status' => $duplicateOrder->status->value,
                    'created_at' => optional($duplicateOrder->created_at)?->toIso8601String(),
                    'receiver_name' => $duplicateOrder->receiver_name,
                ] : null,
                'resolution_status' => $metadata['resolution_status'] ?? 'pending',
                'resolved_at' => $metadata['resolved_at'] ?? null,
                'resolved_by' => $resolvedBy ? [
                    'id' => $resolvedBy->id,
                    'name' => $resolvedBy->name,
                ] : null,
            ];
        })->values()->all();
    }
}
