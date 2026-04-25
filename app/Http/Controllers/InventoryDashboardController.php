<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Procurement\Enums\PoStatus;
use App\Domain\Procurement\Enums\PrStatus;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Product\Models\InventoryMovement;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryDashboardController extends Controller
{
    public function index()
    {
        $stockValue = (float) DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->sum(DB::raw('ps.current_stock * COALESCE(p.cost_price, 0)'));

        $lowStockCount = ProductStock::whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->count();

        $stats = [
            'total_products'     => Product::where('is_active', true)->count(),
            'total_supplies'     => Supply::where('is_active', true)->count(),
            'total_warehouses'   => Warehouse::where('is_active', true)->count(),
            'stock_value'        => $stockValue,
            'low_stock_count'    => $lowStockCount,
            'pending_prs'        => PurchaseRequest::where('status', PrStatus::SUBMITTED)->count(),
            'open_pos'           => PurchaseOrder::whereIn('status', [PoStatus::SENT, PoStatus::PARTIALLY_RECEIVED])->count(),
        ];

        $recentMovements = InventoryMovement::with(['product:id,sku,name'])
            ->latest()
            ->limit(20)
            ->get(['id', 'product_id', 'type', 'quantity', 'created_at', 'notes']);

        $lowStock = ProductStock::with(['product:id,sku,name', 'warehouse:id,name'])
            ->whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->orderByRaw('(current_stock - reserved_stock) ASC')
            ->limit(10)
            ->get();

        return Inertia::render('Inventory/Dashboard', [
            'stats'            => $stats,
            'recent_movements' => $recentMovements,
            'low_stock'        => $lowStock,
        ]);
    }

    public function movements(\Illuminate\Http\Request $request)
    {
        $movements = InventoryMovement::with(['product:id,sku,name', 'warehouse:id,name', 'location:id,code', 'performer:id,name'])
            ->when($request->type,         fn ($q, $v) => $q->where('type', $v))
            ->when($request->product_id,   fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->warehouse_id, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->from,         fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->to,           fn ($q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59'))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Inventory/Movements', [
            'movements' => $movements,
            'filters'   => $request->only(['type', 'product_id', 'warehouse_id', 'from', 'to']),
        ]);
    }
}
