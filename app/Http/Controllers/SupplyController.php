<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyMovement;
use App\Domain\Inventory\Models\UnitOfMeasure;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\SupplyStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use RuntimeException;

class SupplyController extends Controller
{
    public function __construct(private readonly SupplyStockService $stockService) {}

    public function index(Request $request)
    {
        $supplies = Supply::query()
            ->with(['uom:id,name,abbreviation', 'stocks.warehouse:id,name,code'])
            ->when($request->search, fn ($q, $v) =>
                $q->where(fn ($w) => $w->where('name', 'ILIKE', "%{$v}%")
                    ->orWhere('sku', 'ILIKE', "%{$v}%")))
            ->when($request->category, fn ($q, $v) => $q->where('category', $v))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'total' => Supply::count(),
            'active' => Supply::where('is_active', true)->count(),
            'low_stock' => Supply::whereHas('stocks', fn ($q) =>
                $q->whereRaw('(current_stock - reserved_stock) <= reorder_point')
                    ->where('reorder_point', '>', 0)
            )->count(),
            'categories' => Supply::distinct()->pluck('category')->filter()->values(),
        ];

        return Inertia::render('Inventory/Supplies/Index', [
            'supplies' => $supplies,
            'stats' => $stats,
            'filters' => $request->only(['search', 'category', 'status']),
            'uoms' => UnitOfMeasure::where('is_active', true)->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'warehouses' => Warehouse::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'code']),
            'recent_movements' => SupplyMovement::with(['supply:id,sku,name', 'warehouse:id,name,code', 'performer:id,name'])
                ->latest()
                ->limit(15)
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sku' => 'required|string|max:60|unique:supplies,sku',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:60',
            'uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'cost_price' => 'required|numeric|min:0',
            'min_stock_level' => 'nullable|integer|min:0',
            'reorder_point' => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'initial_stock' => 'nullable|integer|min:0',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);

        try {
            DB::transaction(function () use ($data, $request) {
                $supply = Supply::create([
                    'sku' => $data['sku'],
                    'name' => $data['name'],
                    'category' => $data['category'] ?? null,
                    'uom_id' => $data['uom_id'] ?? null,
                    'cost_price' => $data['cost_price'],
                    'min_stock_level' => $data['min_stock_level'] ?? 0,
                    'reorder_point' => $data['reorder_point'] ?? 10,
                    'description' => $data['description'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                ]);

                $warehouse = $this->warehouse($data['warehouse_id'] ?? null);
                $initialStock = (int) ($data['initial_stock'] ?? 0);

                if ($initialStock > 0) {
                    $this->stockService->stockIn(
                        supplyId: (int) $supply->id,
                        warehouseId: (int) $warehouse->id,
                        locationId: null,
                        quantity: $initialStock,
                        notes: 'Initial material stock',
                        performedBy: $request->user()?->id,
                    );
                } else {
                    $supply->stocks()->firstOrCreate(
                        ['warehouse_id' => $warehouse->id, 'location_id' => null],
                        ['current_stock' => 0, 'reserved_stock' => 0, 'reorder_point' => $data['reorder_point'] ?? 10]
                    );
                }
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.supplies.index')->with('success', 'Material added.');
    }

    public function update(Request $request, Supply $supply)
    {
        $data = $request->validate([
            'sku' => 'required|string|max:60|unique:supplies,sku,' . $supply->id,
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:60',
            'uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'cost_price' => 'required|numeric|min:0',
            'min_stock_level' => 'nullable|integer|min:0',
            'reorder_point' => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $supply->update($data);

        return back()->with('success', 'Material updated.');
    }

    public function adjustStock(Request $request, Supply $supply)
    {
        $data = $request->validate([
            'type' => 'required|in:stock_in,stock_out,adjustment',
            'quantity' => 'required|integer|min:1',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $warehouse = $this->warehouse($data['warehouse_id'] ?? null);

            match ($data['type']) {
                'stock_in' => $this->stockService->stockIn(
                    supplyId: (int) $supply->id,
                    warehouseId: (int) $warehouse->id,
                    locationId: null,
                    quantity: (int) $data['quantity'],
                    notes: $data['notes'] ?? null,
                    performedBy: $request->user()?->id,
                ),
                'stock_out' => $this->stockService->stockOut(
                    supplyId: (int) $supply->id,
                    warehouseId: (int) $warehouse->id,
                    locationId: null,
                    quantity: (int) $data['quantity'],
                    notes: $data['notes'] ?? null,
                    performedBy: $request->user()?->id,
                ),
                'adjustment' => $this->stockService->adjustStock(
                    supplyId: (int) $supply->id,
                    warehouseId: (int) $warehouse->id,
                    locationId: null,
                    newQuantity: (int) $data['quantity'],
                    notes: $data['notes'] ?? null,
                    performedBy: $request->user()?->id,
                ),
            };
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Material stock updated.');
    }

    public function destroy(Supply $supply)
    {
        $supply->delete();

        return redirect()->route('inventory.supplies.index')->with('success', 'Material archived.');
    }

    private function warehouse(?int $warehouseId): Warehouse
    {
        $warehouse = $warehouseId
            ? Warehouse::where('is_active', true)->find($warehouseId)
            : Warehouse::default();

        if (! $warehouse) {
            throw new RuntimeException('Create an active warehouse before posting material stock.');
        }

        return $warehouse;
    }
}
