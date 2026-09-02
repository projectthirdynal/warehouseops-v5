<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Modules\Inventory\Models\DeadStock;
use Modules\Inventory\Models\Supply;
use Modules\Inventory\Models\SupplyMovement;
use Modules\Inventory\Models\SupplyStock;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\MovementAuditTrailService;
use Modules\Products\Models\InventoryMovement;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductStock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DeadStockController extends Controller
{
    public function index(Request $request): Response
    {
        $entries = DeadStock::query()
            ->with([
                'supply:id,sku,name,section,stock_category',
                'product:id,sku,name,category',
                'warehouse:id,name,code',
                'recorder:id,name',
            ])
            ->when($request->item_type && $request->item_type !== 'all', fn ($q) => $q->where('item_type', $request->item_type))
            ->when($request->warehouse_id, fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $totalDeadValue = DeadStock::sum('total_value');

        // Supplies for selector: id, sku, name, section, cost_price, and aggregated stock per warehouse
        $supplies = Supply::query()
            ->where('is_active', true)
            ->with(['stocks.warehouse:id,name,code'])
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'section', 'stock_category', 'cost_price'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'sku' => $s->sku,
                'name' => $s->name,
                'section' => $s->section,
                'category' => $s->stock_category,
                'cost_price' => (float) $s->cost_price,
                'stocks' => $s->stocks->map(fn ($st) => [
                    'warehouse_id' => $st->warehouse_id,
                    'warehouse_name' => $st->warehouse?->name,
                    'available' => $st->current_stock - $st->reserved_stock,
                ]),
            ]);

        // Products for selector
        $products = Product::query()
            ->where('is_active', true)
            ->with(['stocks.warehouse:id,name,code'])
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'category', 'cost_price'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category,
                'cost_price' => (float) $p->cost_price,
                'stocks' => $p->stocks->map(fn ($st) => [
                    'warehouse_id' => $st->warehouse_id,
                    'warehouse_name' => $st->warehouse?->name,
                    'available' => $st->current_stock - $st->reserved_stock,
                ]),
            ]);

        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']);

        // Supplies currently classified as DEAD (badge status) — shown separately from write-off ledger
        $deadSupplies = Supply::query()
            ->where('stock_status', 'DEAD')
            ->where('is_active', true)
            ->with(['stocks.warehouse:id,name,code', 'uom:id,abbreviation'])
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'section', 'stock_category', 'cost_price', 'stock_status_override', 'uom_id'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'sku' => $s->sku,
                'name' => $s->name,
                'section' => $s->section,
                'category' => $s->stock_category,
                'cost_price' => (float) $s->cost_price,
                'uom' => $s->uom?->abbreviation,
                'stock_status_override' => $s->stock_status_override,
                'total_stock' => (int) $s->stocks->sum('current_stock'),
                'total_value' => (float) $s->cost_price * $s->stocks->sum('current_stock'),
                'warehouses' => $s->stocks->map(fn ($st) => [
                    'name' => $st->warehouse?->name ?? 'Default',
                    'available' => $st->current_stock - $st->reserved_stock,
                ])->values(),
            ]);

        return Inertia::render('Inventory/DeadStock/Index', [
            'entries' => $entries,
            'total_dead_value' => (float) $totalDeadValue,
            'dead_supplies' => $deadSupplies,
            'supplies' => $supplies,
            'products' => $products,
            'warehouses' => $warehouses,
            'filters' => $request->only(['item_type', 'warehouse_id', 'from', 'to']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'item_type' => ['required', 'in:supply,product'],
            'supply_id' => ['required_if:item_type,supply', 'nullable', 'integer', 'exists:supplies,id'],
            'product_id' => ['required_if:item_type,product', 'nullable', 'integer', 'exists:products,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $qty = (int) $data['quantity'];
        $unitCost = (float) $data['unit_cost'];

        DB::transaction(function () use ($data, $qty, $unitCost, $request): void {
            // Auto write-off: deduct from stock with row locking and available check
            if ($data['item_type'] === 'supply') {
                $stock = SupplyStock::lockForUpdate()
                    ->where('supply_id', $data['supply_id'])
                    ->when($data['warehouse_id'], fn ($q) => $q->where('warehouse_id', $data['warehouse_id']))
                    ->first();

                $available = $stock ? ($stock->current_stock - $stock->reserved_stock) : 0;
                if ($available < $qty) {
                    throw new \RuntimeException("Insufficient available supply stock. Available: {$available}, requested: {$qty}");
                }

                $stock->current_stock = max(0, $stock->current_stock - $qty);
                $stock->last_movement_at = now();
                $stock->save();

                $movement = SupplyMovement::create([
                    'supply_id' => $data['supply_id'],
                    'warehouse_id' => $data['warehouse_id'] ?? $stock->warehouse_id,
                    'type' => 'WRITE_OFF',
                    'quantity' => -$qty,
                    'notes' => 'Dead stock write-off: '.($data['reason'] ?? 'No reason'),
                    'performed_by' => $request->user()?->id,
                ]);

                app(MovementAuditTrailService::class)->recordSupplyMovement(
                    $movement,
                    beforeQuantity: $beforeQty,
                    afterQuantity: $beforeQty - $qty,
                    beforeReserved: (int) $stock->reserved_stock,
                    afterReserved: (int) $stock->reserved_stock,
                    reasonNotes: $data['reason'] ?? null,
                    request: $request,
                );
            } else {
                $stock = ProductStock::lockForUpdate()
                    ->where('product_id', $data['product_id'])
                    ->whereNull('variant_id')
                    ->when($data['warehouse_id'], fn ($q) => $q->where('warehouse_id', $data['warehouse_id']))
                    ->first();

                $available = $stock ? ($stock->current_stock - $stock->reserved_stock) : 0;
                if ($available < $qty) {
                    throw new \RuntimeException("Insufficient available product stock. Available: {$available}, requested: {$qty}");
                }

                $beforeQty = (int) $stock->current_stock;

                $stock->current_stock = max(0, $stock->current_stock - $qty);
                $stock->last_movement_at = now();
                $stock->save();

                $movement = InventoryMovement::create([
                    'product_id' => $data['product_id'],
                    'variant_id' => null,
                    'warehouse_id' => $data['warehouse_id'] ?? $stock->warehouse_id,
                    'type' => 'WRITE_OFF',
                    'quantity' => -$qty,
                    'notes' => 'Dead stock write-off: '.($data['reason'] ?? 'No reason'),
                    'performed_by' => $request->user()?->id,
                ]);

                app(MovementAuditTrailService::class)->recordProductMovement(
                    $movement,
                    beforeQuantity: $beforeQty,
                    afterQuantity: $beforeQty - $qty,
                    beforeReserved: (int) $stock->reserved_stock,
                    afterReserved: (int) $stock->reserved_stock,
                    reasonNotes: $data['reason'] ?? null,
                    request: $request,
                );
            }

            DeadStock::create([
                'item_type' => $data['item_type'],
                'supply_id' => $data['supply_id'] ?? null,
                'product_id' => $data['product_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total_value' => $qty * $unitCost,
                'reason' => $data['reason'] ?? null,
                'recorded_by' => $request->user()?->id,
            ]);
        });

        return back()->with('success', 'Dead stock entry recorded and stock written off.');
    }
}
