<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Services\OrderFulfillmentService;
use App\Exports\OrderAddressExport;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CheckerController extends Controller
{
    public function __construct(
        private OrderFulfillmentService $fulfillmentService,
    ) {}

    /**
     * Display the checker review queue — orders pending QA confirmation.
     */
    public function queue(Request $request): Response
    {
        $query = Order::with([
            'lead',
            'customer',
            'product',
            'variant',
            'agent',
            'shopItems',
        ])
            ->where('status', OrderStatus::QA_PENDING)
            ->orderBy('created_at', 'desc');

        if ($request->has('search') && $request->string('search')->isNotEmpty()) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'ilike', "%{$search}%")
                    ->orWhere('receiver_name', 'ilike', "%{$search}%")
                    ->orWhere('receiver_phone', 'ilike', "%{$search}%");
            });
        }

        $orders = $query->paginate(20)->withQueryString();

        return Inertia::render('Checker/Queue', [
            'orders' => $orders,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Show a single order for checker review.
     */
    public function show(Order $order): Response
    {
        $order->load([
            'lead',
            'customer',
            'product',
            'variant',
            'agent',
            'shopItems.product',
            'shopItems.variant',
        ]);

        return Inertia::render('Checker/Detail', [
            'order' => new OrderResource($order),
        ]);
    }

    /**
     * Approve an order — sends it to courier fulfillment.
     */
    public function approve(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== OrderStatus::QA_PENDING) {
            return response()->json([
                'message' => 'Order is not pending review',
            ], 422);
        }

        $this->fulfillmentService->approve($order, auth()->id());

        return response()->json([
            'message' => 'Order approved and sent to fulfillment',
            'order' => $order->fresh(),
        ]);
    }

    /**
     * Reject an order — cancels it and returns the lead to the pool.
     */
    public function reject(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($order->status !== OrderStatus::QA_PENDING) {
            return response()->json([
                'message' => 'Order is not pending review',
            ], 422);
        }

        $this->fulfillmentService->reject($order, $validated['reason'], auth()->id());

        return response()->json([
            'message' => 'Order rejected. Lead returned to pool.',
            'order' => $order->fresh(),
        ]);
    }

    /**
     * Get counts for the checker dashboard.
     */
    public function counts(): JsonResponse
    {
        return response()->json([
            'pending' => Order::where('status', OrderStatus::QA_PENDING)->count(),
            'approved_today' => Order::where('status', OrderStatus::QA_APPROVED)
                ->whereDate('confirmed_at', today())
                ->count(),
            'rejected_today' => Order::where('status', OrderStatus::QA_REJECTED)
                ->whereDate('updated_at', today())
                ->count(),
        ]);
    }

    public function export(Request $request)
    {
        $query = Order::query()
            ->where('status', OrderStatus::QA_PENDING)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where(function ($sub) use ($search) {
                    $sub->where('order_number', 'ilike', "%{$search}%")
                        ->orWhere('receiver_name', 'ilike', "%{$search}%")
                        ->orWhere('receiver_phone', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc');

        return (new OrderAddressExport($query))->download('checker-queue-'.now()->format('Ymd-His').'.xlsx');
    }
}
