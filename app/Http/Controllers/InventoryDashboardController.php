<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Procurement\Enums\PoStatus;
use App\Domain\Procurement\Enums\PrStatus;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Inventory\Models\StockAdjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryDashboardController extends Controller
{
    public function index()
    {
        $since30 = now()->subDays(30)->startOfDay();

        // ── Stats (2-minute cache — counts change frequently) ────────
        $stats = Cache::remember('inv_dashboard_stats', 120, function () {
            $supplyStockValue = (float) DB::table('supply_stocks as ss')
                ->join('supplies as s', 's.id', '=', 'ss.supply_id')
                ->whereNotNull('ss.warehouse_id')
                ->where('s.is_active', true)
                ->whereNull('s.deleted_at')
                ->sum(DB::raw('ss.current_stock * COALESCE(s.cost_price, 0)'));

            $supplyLowStockCount = SupplyStock::whereRaw('(supply_stocks.current_stock - supply_stocks.reserved_stock) <= supply_stocks.reorder_point')
                ->where('supply_stocks.reorder_point', '>', 0)
                ->whereHas('supply', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))
                ->whereNotNull('warehouse_id')
                ->count();

            $deadThreshold = now()->subDays(90);
            $nonMovingSupplies = DB::table('supply_stocks as ss')
                ->join('supplies as s', 's.id', '=', 'ss.supply_id')
                ->where('ss.current_stock', '>', 0)
                ->whereNull('s.deleted_at')
                ->whereRaw('CASE WHEN COALESCE(ss.last_movement_at, s.created_at) > s.created_at THEN COALESCE(ss.last_movement_at, s.created_at) ELSE s.created_at END < ?', [$deadThreshold])
                ->count();

            $outOfStockCount = Supply::where('is_active', true)
                ->whereNull('deleted_at')
                ->whereRaw('(SELECT COALESCE(SUM(current_stock - reserved_stock), 0) FROM supply_stocks WHERE supply_stocks.supply_id = supplies.id) <= 0')
                ->count();

            return [
                'total_supplies'      => Supply::where('is_active', true)->count(),
                'total_warehouses'    => Warehouse::where('is_active', true)->count(),
                'stock_value'         => $supplyStockValue,
                'supply_low_stock'    => $supplyLowStockCount,
                'pending_adjustments' => StockAdjustment::where('status', 'PENDING')->count(),
                'pending_prs'         => PurchaseRequest::where('status', PrStatus::SUBMITTED)->count(),
                'open_pos'            => PurchaseOrder::whereIn('status', [PoStatus::SENT, PoStatus::PARTIALLY_RECEIVED])->count(),
                'non_moving_supplies' => $nonMovingSupplies,
                'out_of_stock'        => $outOfStockCount,
                // today_scans intentionally excluded — added live below
                'today_scans'         => 0,
            ];
        });

        // ── Today's scans — always live ──────────────────────────────
        $stats['today_scans'] = DB::table('supply_movements')
            ->whereDate('created_at', now()->toDateString())
            ->count();

        // ── Recent supply movements — always live (last 20) ──────────
        $recentSupplyMovements = DB::table('supply_movements as sm')
            ->leftJoin('supplies as s', 's.id', '=', 'sm.supply_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
            ->select([
                'sm.id', 'sm.type', 'sm.quantity', 'sm.notes', 'sm.created_at',
                's.id as supply_id', 's.sku as supply_sku', 's.name as supply_name',
                'w.id as warehouse_id', 'w.name as warehouse_name',
            ])
            ->latest('sm.created_at')
            ->limit(20)
            ->get()
            ->map(fn ($m) => [
                'id'         => $m->id,
                'type'       => $m->type,
                'quantity'   => (int) $m->quantity,
                'notes'      => $m->notes,
                'created_at' => $m->created_at,
                'supply'     => $m->supply_id ? ['id' => $m->supply_id, 'sku' => $m->supply_sku, 'name' => $m->supply_name] : null,
                'warehouse'  => $m->warehouse_id ? ['id' => $m->warehouse_id, 'name' => $m->warehouse_name] : null,
            ]);

        // ── Chart / table data (5-minute cache) ──────────────────────
        [
            $supplyLowStock,
            $supplyMovementTrend,
            $stockStatusDistribution,
            $sectionBreakdown,
            $topSupplyMovers,
            $warehouseStockSummary,
            $supplyStockValue,
        ] = Cache::remember('inv_dashboard_charts', 300, function () use ($since30) {
            $supplyLowStock = DB::table('supply_stocks as ss')
                ->join('supplies as s', 's.id', '=', 'ss.supply_id')
                ->leftJoin('warehouses as w', 'w.id', '=', 'ss.warehouse_id')
                ->whereNotNull('ss.warehouse_id')
                ->where('s.is_active', true)
                ->whereNull('s.deleted_at')
                ->where('ss.reorder_point', '>', 0)
                ->whereRaw('(ss.current_stock - ss.reserved_stock) <= ss.reorder_point')
                ->orderByRaw('(ss.current_stock - ss.reserved_stock) ASC')
                ->limit(10)
                ->select([
                    'ss.id', 's.name as supply_name', 's.sku',
                    'w.name as warehouse_name',
                    'ss.current_stock', 'ss.reserved_stock', 'ss.reorder_point',
                    DB::raw('CASE WHEN ss.current_stock - ss.reserved_stock > 0 THEN ss.current_stock - ss.reserved_stock ELSE 0 END as available_stock'),
                ])
                ->get();

            $supplyMovementTrend = DB::table('supply_movements')
                ->where('created_at', '>=', $since30)
                ->selectRaw("DATE(created_at) as date,
                    SUM(CASE WHEN type = 'STOCK_IN' AND quantity > 0 THEN quantity ELSE 0 END) as stock_in,
                    SUM(CASE WHEN type = 'STOCK_OUT' THEN ABS(quantity) ELSE 0 END) as stock_out,
                    SUM(CASE WHEN type = 'ADJUSTMENT' THEN ABS(quantity) ELSE 0 END) as adjustments")
                ->groupByRaw('DATE(created_at)')
                ->orderBy('date')
                ->get();

            $stockStatusDistribution = DB::table('supplies')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->select('stock_status', DB::raw('COUNT(*) as count'))
                ->groupBy('stock_status')
                ->pluck('count', 'stock_status')
                ->all();

            $sectionBreakdown = DB::table('supplies')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->select('section', DB::raw('COUNT(*) as count'))
                ->groupBy('section')
                ->pluck('count', 'section')
                ->all();

            $topSupplyMovers = DB::table('supply_movements as sm')
                ->join('supplies as s', 's.id', '=', 'sm.supply_id')
                ->where('sm.created_at', '>=', $since30)
                ->whereNotNull('sm.warehouse_id')
                ->whereNull('s.deleted_at')
                ->where('s.is_active', true)
                ->select('s.id', 's.sku', 's.name', DB::raw('SUM(ABS(sm.quantity)) as total_qty'))
                ->groupBy('s.id', 's.sku', 's.name')
                ->orderByRaw('total_qty DESC')
                ->limit(5)
                ->get();

            $warehouseStockSummary = DB::table('warehouses as w')
                ->where('w.is_active', true)
                ->leftJoinSub(
                    DB::table('supply_stocks as ss')
                        ->join('supplies as s', 's.id', '=', 'ss.supply_id')
                        ->whereNotNull('ss.warehouse_id')
                        ->where('s.is_active', true)
                        ->whereNull('s.deleted_at')
                        ->select('ss.warehouse_id', DB::raw('COUNT(DISTINCT ss.supply_id) as supply_units'), DB::raw('SUM(ss.current_stock * COALESCE(s.cost_price, 0)) as supply_value'))
                        ->groupBy('ss.warehouse_id'),
                    'sv', 'sv.warehouse_id', '=', 'w.id'
                )
                ->select([
                    'w.id', 'w.name', 'w.code',
                    DB::raw('COALESCE(sv.supply_units, 0) as supply_units'),
                    DB::raw('COALESCE(sv.supply_value, 0) as supply_value'),
                ])
                ->get();

            $supplyStockValue = (float) DB::table('supply_stocks as ss')
                ->join('supplies as s', 's.id', '=', 'ss.supply_id')
                ->whereNotNull('ss.warehouse_id')
                ->where('s.is_active', true)
                ->whereNull('s.deleted_at')
                ->sum(DB::raw('ss.current_stock * COALESCE(s.cost_price, 0)'));

            return [
                $supplyLowStock,
                $supplyMovementTrend,
                $stockStatusDistribution,
                $sectionBreakdown,
                $topSupplyMovers,
                $warehouseStockSummary,
                $supplyStockValue,
            ];
        });

        return Inertia::render('Inventory/Dashboard', [
            'stats'                       => $stats,
            'recent_supply_movements'     => $recentSupplyMovements,
            'supply_low_stock'            => $supplyLowStock,
            'supply_movement_trend'       => $supplyMovementTrend,
            'warehouse_stock_summary'     => $warehouseStockSummary,
            'supply_stock_value'          => $supplyStockValue,
            'stock_status_distribution'   => $stockStatusDistribution,
            'section_breakdown'           => $sectionBreakdown,
            'top_supply_movers'           => $topSupplyMovers,
        ]);
    }

    public function movements(\Illuminate\Http\Request $request)
    {
        $stream = $request->input('stream', 'products'); // 'products' | 'materials'
        $type   = $request->input('type');
        if ($type === 'all') $type = null;

        if ($stream === 'materials') {
            // ── Supply movements ─────────────────────────────────────
            $query = DB::table('supply_movements as sm')
                ->leftJoin('supplies as s',    's.id',  '=', 'sm.supply_id')
                ->leftJoin('warehouses as w',  'w.id',  '=', 'sm.warehouse_id')
                ->leftJoin('users as u',       'u.id',  '=', 'sm.performed_by')
                ->when($type,                fn ($q, $v) => $q->where('sm.type', $v))
                ->when($request->warehouse_id, fn ($q, $v) => $q->where('sm.warehouse_id', $v))
                ->when($request->from,         fn ($q, $v) => $q->where('sm.created_at', '>=', Carbon::parse($v)->startOfDay()))
                ->when($request->to,           fn ($q, $v) => $q->where('sm.created_at', '<=', Carbon::parse($v)->endOfDay()))
                ->select([
                    'sm.id',
                    DB::raw("'material' as stream"),
                    'sm.type',
                    'sm.quantity',
                    'sm.batch_number',
                    'sm.notes',
                    'sm.created_at',
                    's.id as item_id',
                    's.sku as item_sku',
                    's.name as item_name',
                    DB::raw('NULL as location_code'),
                    'w.id as warehouse_id',
                    'w.name as warehouse_name',
                    'u.id as performer_id',
                    'u.name as performer_name',
                ])
                ->latest('sm.created_at');

            $movements = $query->paginate(50)->withQueryString();

        } else {
            // ── Product / inventory movements ────────────────────────
            $query = DB::table('inventory_movements as im')
                ->leftJoin('products as p',   'p.id',  '=', 'im.product_id')
                ->leftJoin('warehouses as w',  'w.id',  '=', 'im.warehouse_id')
                ->leftJoin('warehouse_locations as l', 'l.id', '=', 'im.location_id')
                ->leftJoin('users as u',       'u.id',  '=', 'im.performed_by')
                ->when($type,                fn ($q, $v) => $q->where('im.type', $v))
                ->when($request->warehouse_id, fn ($q, $v) => $q->where('im.warehouse_id', $v))
                ->when($request->from,         fn ($q, $v) => $q->where('im.created_at', '>=', Carbon::parse($v)->startOfDay()))
                ->when($request->to,           fn ($q, $v) => $q->where('im.created_at', '<=', Carbon::parse($v)->endOfDay()))
                ->select([
                    'im.id',
                    DB::raw("'product' as stream"),
                    'im.type',
                    'im.quantity',
                    'im.batch_number',
                    'im.notes',
                    'im.created_at',
                    'p.id as item_id',
                    'p.sku as item_sku',
                    'p.name as item_name',
                    'l.code as location_code',
                    'w.id as warehouse_id',
                    'w.name as warehouse_name',
                    'u.id as performer_id',
                    'u.name as performer_name',
                ])
                ->latest('im.created_at');

            $movements = $query->paginate(50)->withQueryString();
        }

        // Transform to uniform shape
        $movements->through(fn ($row) => [
            'id'           => $row->id,
            'stream'       => $row->stream,
            'type'         => $row->type,
            'quantity'     => (int) $row->quantity,
            'batch_number' => $row->batch_number,
            'notes'        => $row->notes,
            'created_at'   => $row->created_at,
            'item'         => $row->item_id ? [
                'id'  => $row->item_id,
                'sku' => $row->item_sku,
                'name'=> $row->item_name,
            ] : null,
            'location_code'  => $row->location_code,
            'warehouse'    => $row->warehouse_id ? [
                'id'   => $row->warehouse_id,
                'name' => $row->warehouse_name,
            ] : null,
            'performer'    => $row->performer_id ? [
                'id'   => $row->performer_id,
                'name' => $row->performer_name,
            ] : null,
        ]);

        return Inertia::render('Inventory/Movements', [
            'movements' => $movements,
            'filters'   => $request->only(['stream', 'type', 'warehouse_id', 'from', 'to']),
        ]);
    }

    /**
     * Non-moving / dead stock report.
     * Lists products and supplies with on-hand stock but no movement within
     * the requested threshold (default 90 days).
     */
    public function nonMoving(Request $request): \Inertia\Response
    {
        $days        = max(1, (int) $request->input('days', 90));
        $threshold   = now()->subDays($days);
        $type        = $request->input('type', 'all'); // all | products | supplies
        $productPage = max(1, (int) $request->input('product_page', 1));
        $supplyPage  = max(1, (int) $request->input('supply_page', 1));

        $products = null;
        if (in_array($type, ['all', 'products'])) {
            $products = DB::table('product_stocks as ps')
                ->join('products as p', 'p.id', '=', 'ps.product_id')
                ->leftJoin('warehouses as w', 'w.id', '=', 'ps.warehouse_id')
                ->where('ps.current_stock', '>', 0)
                ->whereRaw('CASE WHEN COALESCE(ps.last_movement_at, p.created_at) > p.created_at THEN COALESCE(ps.last_movement_at, p.created_at) ELSE p.created_at END < ?', [$threshold])
                ->whereNull('p.deleted_at')
                ->select([
                    'p.id as product_id',
                    'p.sku',
                    'p.name as item_name',
                    'p.category',
                    'w.id as warehouse_id',
                    'w.name as warehouse_name',
                    'ps.current_stock',
                    'ps.reserved_stock',
                    DB::raw('CASE WHEN ps.current_stock - ps.reserved_stock > 0 THEN ps.current_stock - ps.reserved_stock ELSE 0 END as available_stock'),
                    DB::raw('ps.current_stock * COALESCE(p.cost_price, 0) as stock_value'),
                    'ps.last_movement_at',
                    'ps.last_restock_at',
                    DB::raw("'product' as item_type"),
                ])
                ->orderByRaw('CASE WHEN ps.last_movement_at IS NULL THEN 0 ELSE 1 END, ps.last_movement_at ASC')
                ->paginate(50, ['*'], 'product_page', $productPage)->withQueryString();
        }

        $supplies = null;
        if (in_array($type, ['all', 'supplies'])) {
            $supplies = DB::table('supply_stocks as ss')
                ->join('supplies as s', 's.id', '=', 'ss.supply_id')
                ->leftJoin('warehouses as w', 'w.id', '=', 'ss.warehouse_id')
                ->where('ss.current_stock', '>', 0)
                ->whereRaw('CASE WHEN COALESCE(ss.last_movement_at, s.created_at) > s.created_at THEN COALESCE(ss.last_movement_at, s.created_at) ELSE s.created_at END < ?', [$threshold])
                ->whereNull('s.deleted_at')
                ->select([
                    's.id as supply_id',
                    's.sku',
                    's.name as item_name',
                    's.category',
                    'w.id as warehouse_id',
                    'w.name as warehouse_name',
                    'ss.current_stock',
                    'ss.reserved_stock',
                    DB::raw('CASE WHEN ss.current_stock - ss.reserved_stock > 0 THEN ss.current_stock - ss.reserved_stock ELSE 0 END as available_stock'),
                    DB::raw('ss.current_stock * COALESCE(s.cost_price, 0) as stock_value'),
                    'ss.last_movement_at',
                    'ss.last_restock_at',
                    DB::raw("'supply' as item_type"),
                ])
                ->orderByRaw('CASE WHEN ss.last_movement_at IS NULL THEN 0 ELSE 1 END, ss.last_movement_at ASC')
                ->paginate(50, ['*'], 'supply_page', $supplyPage)->withQueryString();
        }

        // Compute total dead value — respects the active type filter
        $productDeadValue = 0;
        if (in_array($type, ['all', 'products'])) {
            $productDeadValue = DB::table('product_stocks as ps')
                ->join('products as p', 'p.id', '=', 'ps.product_id')
                ->where('ps.current_stock', '>', 0)
                ->whereRaw('CASE WHEN COALESCE(ps.last_movement_at, p.created_at) > p.created_at THEN COALESCE(ps.last_movement_at, p.created_at) ELSE p.created_at END < ?', [$threshold])
                ->whereNull('p.deleted_at')
                ->sum(DB::raw('ps.current_stock * COALESCE(p.cost_price, 0)'));
        }

        $supplyDeadValue = 0;
        if (in_array($type, ['all', 'supplies'])) {
            $supplyDeadValue = DB::table('supply_stocks as ss')
                ->join('supplies as s', 's.id', '=', 'ss.supply_id')
                ->where('ss.current_stock', '>', 0)
                ->whereRaw('CASE WHEN COALESCE(ss.last_movement_at, s.created_at) > s.created_at THEN COALESCE(ss.last_movement_at, s.created_at) ELSE s.created_at END < ?', [$threshold])
                ->whereNull('s.deleted_at')
                ->sum(DB::raw('ss.current_stock * COALESCE(s.cost_price, 0)'));
        }

        $totalDeadValue = round((float) ($productDeadValue + $supplyDeadValue), 2);

        return Inertia::render('Inventory/NonMoving', [
            'products'         => $products,
            'supplies'         => $supplies,
            'total_dead_value' => $totalDeadValue,
            'filters'          => [
                'days' => $days,
                'type' => $type,
            ],
        ]);
    }

    /**
     * Realtime unified movement feed — product + supply movements, newest first.
     * Filterable by warehouse, movement type, and optional `since` timestamp
     * for incremental polling (e.g. the frontend polls every 10 s passing the
     * last `server_time` it received as `since`).
     */
    public function liveMovements(Request $request): \Illuminate\Http\JsonResponse
    {
        $since       = $request->input('since');
        $warehouseId = $request->input('warehouse_id');
        $typeFilter  = $request->input('type') ? strtoupper($request->input('type')) : null;
        $perPage     = min(100, max(10, (int) $request->input('per_page', 25)));

        $productQ = DB::table('inventory_movements as im')
            ->join('products as p', 'p.id', '=', 'im.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'im.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'im.performed_by')
            ->select([
                'im.id',
                DB::raw("'product' as source"),
                'im.type',
                'im.quantity',
                'im.notes',
                'im.batch_number',
                'im.created_at',
                'p.id as item_id',
                'p.sku',
                'p.name as item_name',
                'w.id as warehouse_id',
                'w.name as warehouse_name',
                'u.id as performer_id',
                'u.name as performer_name',
            ])
            ->when($since,       fn ($q) => $q->where('im.created_at', '>', Carbon::parse($since)))
            ->when($warehouseId, fn ($q) => $q->where('im.warehouse_id', $warehouseId))
            ->when($typeFilter,  fn ($q) => $q->where('im.type', $typeFilter));

        $supplyQ = DB::table('supply_movements as sm')
            ->join('supplies as s', 's.id', '=', 'sm.supply_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'sm.performed_by')
            ->select([
                'sm.id',
                DB::raw("'supply' as source"),
                'sm.type',
                'sm.quantity',
                'sm.notes',
                'sm.batch_number',
                'sm.created_at',
                's.id as item_id',
                's.sku',
                's.name as item_name',
                'w.id as warehouse_id',
                'w.name as warehouse_name',
                'u.id as performer_id',
                'u.name as performer_name',
            ])
            ->when($since,       fn ($q) => $q->where('sm.created_at', '>', Carbon::parse($since)))
            ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->when($typeFilter,  fn ($q) => $q->where('sm.type', $typeFilter));

        $union = $productQ->unionAll($supplyQ);

        $allBindings = array_merge($productQ->getBindings(), $supplyQ->getBindings());

        $paginated = DB::table(DB::raw("({$union->toSql()}) as movements"))
            ->addBinding($allBindings)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data'        => $paginated->items(),
            'meta'        => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
            'server_time' => now()->toISOString(),
        ]);
    }
}
