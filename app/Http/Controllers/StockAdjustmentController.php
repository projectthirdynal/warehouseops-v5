<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Services\SupplyStockService;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use App\Domain\Inventory\Models\Supply;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly SupplyStockService $supplyStockService,
    ) {}

    public function index(Request $request)
    {
        $adjustments = DB::table('stock_adjustments as sa')
            ->leftJoin('products as p', 'p.id', '=', 'sa.product_id')
            ->leftJoin('supplies as s', 's.id', '=', 'sa.supply_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sa.warehouse_id')
            ->leftJoin('users as sub', 'sub.id', '=', 'sa.submitted_by')
            ->leftJoin('users as apv', 'apv.id', '=', 'sa.approved_by')
            ->select([
                'sa.id', 'sa.reason_code', 'sa.reason_notes',
                'sa.quantity_before', 'sa.quantity_after', 'sa.variance',
                'sa.status', 'sa.created_at', 'sa.approved_at',
                'p.name as product_name', 'p.sku as product_sku',
                's.name as supply_name', 's.sku as supply_sku',
                'w.name as warehouse_name', 'w.code as warehouse_code',
                'sub.name as submitted_by', 'apv.name as approved_by',
            ])
            ->when($request->status && $request->status !== 'all',
                fn ($q) => $q->where('sa.status', $request->status))
            ->when($request->warehouse_id,
                fn ($q) => $q->where('sa.warehouse_id', $request->warehouse_id))
            ->when($request->from, fn ($q) => $q->where('sa.created_at', '>=', $request->from))
            ->when($request->to,   fn ($q) => $q->where('sa.created_at', '<=', $request->to . ' 23:59:59'))
            ->orderByDesc('sa.created_at')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'pending'  => DB::table('stock_adjustments')->where('status', 'PENDING')->count(),
            'approved' => DB::table('stock_adjustments')->where('status', 'APPROVED')->count(),
            'rejected' => DB::table('stock_adjustments')->where('status', 'REJECTED')->count(),
        ];

        return Inertia::render('Inventory/StockAdjustments', [
            'adjustments' => $adjustments,
            'stats'       => $stats,
            'warehouses'  => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'products'    => Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'supplies'    => Supply::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'filters'     => $request->only(['status', 'warehouse_id', 'from', 'to']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'item_type'    => 'required|in:product,supply',
            'product_id'   => 'required_if:item_type,product|nullable|exists:products,id',
            'supply_id'    => 'required_if:item_type,supply|nullable|exists:supplies,id',
            'variant_id'   => 'nullable|exists:product_variants,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity_after' => 'required|integer|min:0',
            'reason_code'  => 'required|string|max:50',
            'reason_notes' => 'nullable|string|max:500',
        ]);

        $warehouseId = (int) $data['warehouse_id'];

        if ($data['item_type'] === 'product') {
            $productId = (int) $data['product_id'];
            $variantId = $data['variant_id'] ? (int) $data['variant_id'] : null;
            $currentQty = (int) (ProductStock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->when($variantId === null, fn ($q) => $q->whereNull('variant_id'))
                ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
                ->value('current_stock') ?? 0);

            DB::table('stock_adjustments')->insert([
                'product_id'      => $productId,
                'variant_id'      => $variantId,
                'warehouse_id'    => $warehouseId,
                'reason_code'     => $data['reason_code'],
                'reason_notes'    => $data['reason_notes'] ?? null,
                'quantity_before' => $currentQty,
                'quantity_after'  => (int) $data['quantity_after'],
                'variance'        => (int) $data['quantity_after'] - $currentQty,
                'status'          => 'PENDING',
                'submitted_by'    => $request->user()->id,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } else {
            $supplyId = (int) $data['supply_id'];
            $currentQty = (int) (DB::table('supply_stocks')
                ->where('supply_id', $supplyId)
                ->where('warehouse_id', $warehouseId)
                ->value('current_stock') ?? 0);

            DB::table('stock_adjustments')->insert([
                'supply_id'       => $supplyId,
                'warehouse_id'    => $warehouseId,
                'reason_code'     => $data['reason_code'],
                'reason_notes'    => $data['reason_notes'] ?? null,
                'quantity_before' => $currentQty,
                'quantity_after'  => (int) $data['quantity_after'],
                'variance'        => (int) $data['quantity_after'] - $currentQty,
                'status'          => 'PENDING',
                'submitted_by'    => $request->user()->id,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        return back()->with('success', 'Stock adjustment submitted for approval.');
    }

    public function approve(Request $request, int $id)
    {
        $adjustment = DB::table('stock_adjustments')->where('id', $id)->first();

        if (! $adjustment || $adjustment->status !== 'PENDING') {
            return back()->with('error', 'Adjustment is not pending.');
        }

        DB::transaction(function () use ($adjustment, $request) {
            if ($adjustment->product_id) {
                $this->stockService->adjustStock(
                    productId: (int) $adjustment->product_id,
                    variantId: $adjustment->variant_id ? (int) $adjustment->variant_id : null,
                    warehouseId: (int) $adjustment->warehouse_id,
                    locationId: null,
                    newQuantity: (int) $adjustment->quantity_after,
                    notes: "Approved adjustment [{$adjustment->reason_code}]: {$adjustment->reason_notes}",
                    performedBy: $request->user()->id,
                );
            } elseif ($adjustment->supply_id) {
                $this->supplyStockService->adjustStock(
                    supplyId: (int) $adjustment->supply_id,
                    warehouseId: (int) $adjustment->warehouse_id,
                    locationId: null,
                    newQuantity: (int) $adjustment->quantity_after,
                    notes: "Approved adjustment [{$adjustment->reason_code}]: {$adjustment->reason_notes}",
                    performedBy: $request->user()->id,
                );
            }

            DB::table('stock_adjustments')->where('id', $adjustment->id)->update([
                'status'      => 'APPROVED',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'updated_at'  => now(),
            ]);
        });

        return back()->with('success', 'Adjustment approved and stock updated.');
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $rejectNote = $data['reason'] ?? null;

        $updated = DB::table('stock_adjustments')
            ->where('id', $id)
            ->where('status', 'PENDING')
            ->update([
                'status'       => 'REJECTED',
                'approved_by'  => $request->user()->id,
                'approved_at'  => now(),
                'reason_notes' => $rejectNote
                    ? DB::raw("COALESCE(reason_notes, '') || ' | Rejected: " . str_replace("'", "''", $rejectNote) . "'")
                    : DB::raw('reason_notes'),
                'updated_at'   => now(),
            ]);

        if (! $updated) {
            return back()->with('error', 'Adjustment not found or already processed.');
        }

        return back()->with('success', 'Adjustment rejected.');
    }

    public function report(Request $request)
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : Carbon::today()->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : Carbon::today()->endOfDay();

        // Base query builder helper
        $base = fn () => DB::table('stock_adjustments as sa')
            ->leftJoin('products as p',  'p.id',  '=', 'sa.product_id')
            ->leftJoin('supplies as s',  's.id',  '=', 'sa.supply_id')
            ->leftJoin('warehouses as w','w.id',  '=', 'sa.warehouse_id')
            ->leftJoin('users as sub',   'sub.id','=', 'sa.submitted_by')
            ->leftJoin('users as apv',   'apv.id','=', 'sa.approved_by')
            ->whereBetween('sa.created_at', [$from, $to])
            ->when($request->status && $request->status !== 'all',
                fn ($q) => $q->where('sa.status', $request->status))
            ->when($request->warehouse_id,
                fn ($q) => $q->where('sa.warehouse_id', $request->warehouse_id))
            ->when($request->reason_code,
                fn ($q) => $q->where('sa.reason_code', $request->reason_code));

        // ── Summary KPIs ──────────────────────────────────────────────────────
        $summary = $base()->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'PENDING'  THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN variance > 0 THEN 1 ELSE 0 END) as positive_count,
            SUM(CASE WHEN variance < 0 THEN 1 ELSE 0 END) as negative_count,
            SUM(CASE WHEN variance = 0 THEN 1 ELSE 0 END) as zero_count,
            COALESCE(SUM(CASE WHEN variance > 0 AND status = 'APPROVED' THEN variance ELSE 0 END), 0) as total_added,
            COALESCE(SUM(CASE WHEN variance < 0 AND status = 'APPROVED' THEN ABS(variance) ELSE 0 END), 0) as total_deducted,
            COALESCE(SUM(CASE WHEN status = 'APPROVED' THEN ABS(variance) ELSE 0 END), 0) as total_units_moved
        ")->first();

        // ── By reason code ────────────────────────────────────────────────────
        $byReason = $base()->selectRaw("
            sa.reason_code,
            COUNT(*) as count,
            SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'PENDING'  THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as rejected,
            COALESCE(SUM(CASE WHEN status = 'APPROVED' THEN variance ELSE 0 END), 0) as net_variance
        ")->groupBy('sa.reason_code')->orderByRaw('count(*) DESC')->get();

        // ── By warehouse ──────────────────────────────────────────────────────
        $byWarehouse = $base()->selectRaw("
            w.name as warehouse_name,
            w.code as warehouse_code,
            COUNT(*) as count,
            SUM(CASE WHEN sa.status = 'APPROVED' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN sa.status = 'PENDING'  THEN 1 ELSE 0 END) as pending,
            COALESCE(SUM(CASE WHEN sa.status = 'APPROVED' AND sa.variance > 0 THEN sa.variance ELSE 0 END), 0) as total_added,
            COALESCE(SUM(CASE WHEN sa.status = 'APPROVED' AND sa.variance < 0 THEN ABS(sa.variance) ELSE 0 END), 0) as total_deducted
        ")->groupBy('w.id', 'w.name', 'w.code')->orderByRaw('count(*) DESC')->get();

        // ── By submitter ──────────────────────────────────────────────────────
        $bySubmitter = $base()->selectRaw("
            sub.name as submitter_name,
            COUNT(*) as count,
            SUM(CASE WHEN sa.status = 'APPROVED' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN sa.status = 'REJECTED' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN sa.status = 'PENDING'  THEN 1 ELSE 0 END) as pending
        ")->groupBy('sub.id', 'sub.name')->orderByRaw('count(*) DESC')->get();

        // ── By hour (today only, useful for daily report) ─────────────────────
        $byHour = (Carbon::parse($from)->isSameDay(Carbon::parse($to)))
            ? $base()->selectRaw("
                EXTRACT(HOUR FROM sa.created_at) as hour,
                COUNT(*) as count,
                SUM(CASE WHEN sa.status = 'APPROVED' THEN 1 ELSE 0 END) as approved
              ")->groupByRaw('EXTRACT(HOUR FROM sa.created_at)')
               ->orderByRaw('EXTRACT(HOUR FROM sa.created_at)')
               ->get()
            : collect();

        // ── Approved high-impact rows (largest variance, approved only) ────────
        $topImpact = $base()->select([
            'sa.id', 'sa.reason_code', 'sa.variance', 'sa.quantity_before',
            'sa.quantity_after', 'sa.created_at', 'sa.approved_at', 'sa.status',
            'sa.reason_notes',
            DB::raw("COALESCE(p.name, s.name) as item_name"),
            DB::raw("COALESCE(p.sku, s.sku) as item_sku"),
            'w.name as warehouse_name',
            'sub.name as submitted_by',
            'apv.name as approved_by',
        ])->where('sa.status', 'APPROVED')
          ->orderByRaw('ABS(sa.variance) DESC')
          ->limit(20)
          ->get();

        // ── Pending (needing action) ──────────────────────────────────────────
        $pendingRows = $base()->select([
            'sa.id', 'sa.reason_code', 'sa.variance', 'sa.quantity_before',
            'sa.quantity_after', 'sa.created_at', 'sa.reason_notes', 'sa.status',
            DB::raw("COALESCE(p.name, s.name) as item_name"),
            DB::raw("COALESCE(p.sku, s.sku) as item_sku"),
            'w.name as warehouse_name',
            'sub.name as submitted_by',
        ])->where('sa.status', 'PENDING')
          ->orderByDesc('sa.created_at')
          ->get();

        // ── Full table for export / drilldown (paginated) ─────────────────────
        $rows = $base()->select([
            'sa.id', 'sa.reason_code', 'sa.reason_notes', 'sa.variance',
            'sa.quantity_before', 'sa.quantity_after', 'sa.status',
            'sa.created_at', 'sa.approved_at',
            DB::raw("COALESCE(p.name, s.name) as item_name"),
            DB::raw("COALESCE(p.sku,  s.sku)  as item_sku"),
            DB::raw("CASE WHEN sa.product_id IS NOT NULL THEN 'product' ELSE 'supply' END as item_type"),
            'w.name as warehouse_name', 'w.code as warehouse_code',
            'sub.name as submitted_by',
            'apv.name as approved_by',
        ])->orderByDesc('sa.created_at')
          ->paginate(50)
          ->withQueryString();

        return Inertia::render('Inventory/AdjustmentReport', [
            'summary'      => $summary,
            'by_reason'    => $byReason,
            'by_warehouse' => $byWarehouse,
            'by_submitter' => $bySubmitter,
            'by_hour'      => $byHour,
            'top_impact'   => $topImpact,
            'pending_rows' => $pendingRows,
            'rows'         => $rows,
            'warehouses'   => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'filters'      => $request->only(['from', 'to', 'status', 'warehouse_id', 'reason_code']),
            'period'       => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function downloadReport(Request $request): StreamedResponse
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : Carbon::today()->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : Carbon::today()->endOfDay();

        $rows = DB::table('stock_adjustments as sa')
            ->leftJoin('products as p',  'p.id',  '=', 'sa.product_id')
            ->leftJoin('supplies as s',  's.id',  '=', 'sa.supply_id')
            ->leftJoin('warehouses as w','w.id',  '=', 'sa.warehouse_id')
            ->leftJoin('users as sub',   'sub.id','=', 'sa.submitted_by')
            ->leftJoin('users as apv',   'apv.id','=', 'sa.approved_by')
            ->whereBetween('sa.created_at', [$from, $to])
            ->when($request->status && $request->status !== 'all',
                fn ($q) => $q->where('sa.status', $request->status))
            ->when($request->warehouse_id,
                fn ($q) => $q->where('sa.warehouse_id', $request->warehouse_id))
            ->when($request->reason_code,
                fn ($q) => $q->where('sa.reason_code', $request->reason_code))
            ->select([
                'sa.id',
                DB::raw("COALESCE(p.sku, s.sku) as sku"),
                DB::raw("COALESCE(p.name, s.name) as item"),
                DB::raw("CASE WHEN sa.product_id IS NOT NULL THEN 'Product' ELSE 'Supply' END as type"),
                'w.name as warehouse',
                'sa.reason_code',
                'sa.quantity_before',
                'sa.quantity_after',
                'sa.variance',
                'sa.status',
                'sub.name as submitted_by',
                'apv.name as approved_by',
                'sa.created_at',
                'sa.approved_at',
                'sa.reason_notes as notes',
            ])
            ->orderByDesc('sa.created_at')
            ->get();

        $filename = 'adjustment_report_' . $from->format('Ymd') . '_' . $to->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'ID', 'SKU', 'Item', 'Type', 'Warehouse', 'Reason Code',
                'Qty Before', 'Qty After', 'Variance', 'Status',
                'Submitted By', 'Approved By', 'Created At', 'Approved At', 'Notes',
            ]);
            foreach ($rows as $row) {
                fputcsv($handle, (array) $row);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
