<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Models\Supply;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockTransferService;
use Modules\Products\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    public function __construct(private StockTransferService $transferService) {}

    public function index(Request $request): Response
    {
        $query = StockTransfer::with([
            'stockable:id,name,sku',
            'fromWarehouse:id,name,code',
            'toWarehouse:id,name,code',
            'requestedBy:id,name',
            'approvedBy:id,name',
        ])
            ->orderByDesc('created_at');

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->warehouse_id) {
            $query->where(function ($q) use ($request) {
                $q->where('from_warehouse_id', $request->warehouse_id)
                    ->orWhere('to_warehouse_id', $request->warehouse_id);
            });
        }
        if ($request->stockable_type) {
            $class = match ($request->stockable_type) {
                'product' => 'App\\Domain\\Product\\Models\\Product',
                'supply' => 'App\\Domain\\Inventory\\Models\\Supply',
                default => null,
            };
            if ($class) {
                $query->where('stockable_type', $class);
            }
        }

        $transfers = $query->paginate(25)->withQueryString();

        return Inertia::render('Inventory/StockTransfers', [
            'transfers' => $transfers,
            'stats' => [
                'pending' => StockTransfer::where('status', StockTransfer::STATUS_PENDING)->count(),
                'completed' => StockTransfer::where('status', StockTransfer::STATUS_COMPLETED)->count(),
                'rejected' => StockTransfer::where('status', StockTransfer::STATUS_REJECTED)->count(),
                'cancelled' => StockTransfer::where('status', StockTransfer::STATUS_CANCELLED)->count(),
            ],
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'supplies' => Supply::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'filters' => $request->only(['status', 'warehouse_id', 'stockable_type']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'stockable_type' => ['required', 'in:product,supply'],
            'stockable_id' => ['required', 'integer', 'min:1'],
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $class = match ($data['stockable_type']) {
            'product' => 'App\\Domain\\Product\\Models\\Product',
            'supply' => 'App\\Domain\\Inventory\\Models\\Supply',
        };
        $data['stockable_type'] = $class;

        try {
            $this->transferService->createTransfer($data, $request->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['quantity' => $e->getMessage()]);
        }

        return back()->with('success', 'Transfer request submitted.');
    }

    public function api(Request $request): JsonResponse
    {
        $query = StockTransfer::with([
            'stockable:id,name,sku',
            'fromWarehouse:id,name,code',
            'toWarehouse:id,name,code',
            'requestedBy:id,name',
            'approvedBy:id,name',
        ])->orderByDesc('created_at');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate(25)->withQueryString());
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $transfer = StockTransfer::findOrFail($id);

        try {
            $this->transferService->approve($transfer, $request->user());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transfer approved and stock moved.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $transfer = StockTransfer::findOrFail($id);

        try {
            $this->transferService->reject($transfer, $request->user(), $request->input('reason'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transfer rejected.');
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $transfer = StockTransfer::findOrFail($id);

        try {
            $this->transferService->cancel($transfer, $request->user());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transfer cancelled.');
    }
}
