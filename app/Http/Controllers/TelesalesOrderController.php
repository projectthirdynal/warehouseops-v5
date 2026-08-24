<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Exports\OrderAddressExport;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TelesalesOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with([
            'customer:id,name,phone,normalized_phone',
            'shopItems:id,order_id,product_name,quantity',
            'agent:id,name',
            'lead:id,assigned_to',
        ])
            ->where('status', '!=', OrderStatus::DRAFT)
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->string('q')->toString();
                $q->where(function ($sub) use ($search) {
                    $sub->where('order_number', 'like', "%{$search}%")
                        ->orWhere('receiver_name', 'like', "%{$search}%")
                        ->orWhere('receiver_phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->string('status')->toString());
            })
            ->when($request->filled('needs_action'), function ($q) {
                $q->whereIn('status', [
                    OrderStatus::PENDING->value,
                    OrderStatus::QA_PENDING->value,
                    OrderStatus::QA_APPROVED->value,
                    OrderStatus::PROCESSING->value,
                ]);
            });

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total' => Order::where('status', '!=', OrderStatus::DRAFT)->count(),
            'pending' => Order::where('status', OrderStatus::PENDING)->count(),
            'qa_pending' => Order::where('status', OrderStatus::QA_PENDING)->count(),
            'processing' => Order::where('status', OrderStatus::PROCESSING)->count(),
            'dispatched' => Order::where('status', OrderStatus::DISPATCHED)->count(),
            'delivered' => Order::where('status', OrderStatus::DELIVERED)->count(),
            'returned' => Order::where('status', OrderStatus::RETURNED)->count(),
        ];

        return Inertia::render('Telesales/Orders/Index', [
            'orders' => $orders,
            'stats' => $stats,
            'filters' => $request->only(['q', 'status', 'needs_action']),
        ]);
    }

    public function export(Request $request)
    {
        $query = Order::query()
            ->where('status', '!=', OrderStatus::DRAFT)
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->string('q')->toString();
                $q->where(function ($sub) use ($search) {
                    $sub->where('order_number', 'like', "%{$search}%")
                        ->orWhere('receiver_name', 'like', "%{$search}%")
                        ->orWhere('receiver_phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->string('status')->toString());
            })
            ->when($request->filled('needs_action'), function ($q) {
                $q->whereIn('status', [
                    OrderStatus::PENDING->value,
                    OrderStatus::QA_PENDING->value,
                    OrderStatus::QA_APPROVED->value,
                    OrderStatus::PROCESSING->value,
                ]);
            })
            ->orderBy('created_at', 'desc');

        return (new OrderAddressExport($query))->download('telesales-orders-address-'.now()->format('Ymd-His').'.xlsx');
    }
}
