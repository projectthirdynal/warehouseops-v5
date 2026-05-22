<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseLocation;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class WarehouseController extends Controller
{
    public function index()
    {
        $warehouses = Warehouse::with(['locations' => fn ($q) => $q->orderBy('code')])
            ->orderBy('name')
            ->get();

        $stockByWarehouse = ProductStock::select('warehouse_id', DB::raw('SUM(current_stock) as total_stock'), DB::raw('SUM(reserved_stock) as total_reserved'))
            ->groupBy('warehouse_id')
            ->pluck(DB::raw('SUM(current_stock)'), 'warehouse_id');

        $supplyStockByWarehouse = DB::table('supply_stocks')
            ->select('warehouse_id', DB::raw('SUM(current_stock) as total_stock'))
            ->groupBy('warehouse_id')
            ->pluck('total_stock', 'warehouse_id');

        $stockValue = DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->select('ps.warehouse_id', DB::raw('SUM(ps.current_stock * COALESCE(p.cost_price, 0)) as value'))
            ->groupBy('ps.warehouse_id')
            ->pluck('value', 'warehouse_id');

        return Inertia::render('Inventory/Warehouses/Index', [
            'warehouses'             => $warehouses,
            'stock_by_warehouse'     => $stockByWarehouse,
            'supply_stock_by_warehouse' => $supplyStockByWarehouse,
            'stock_value_by_warehouse'  => $stockValue,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:120',
            'code'           => 'required|string|max:20|unique:warehouses,code',
            'address'        => 'nullable|string|max:500',
            'contact_person' => 'nullable|string|max:120',
            'contact_phone'  => 'nullable|string|max:30',
            'is_active'      => 'boolean',
            'is_default'     => 'boolean',
        ]);
        if (! empty($data['is_default'])) {
            Warehouse::where('is_default', true)->update(['is_default' => false]);
        }
        Warehouse::create($data);
        return back()->with('success', 'Warehouse added.');
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:120',
            'code'           => ['required', 'string', 'max:20', Rule::unique('warehouses', 'code')->ignore($warehouse->id)],
            'address'        => 'nullable|string|max:500',
            'contact_person' => 'nullable|string|max:120',
            'contact_phone'  => 'nullable|string|max:30',
            'is_active'      => 'boolean',
            'is_default'     => 'boolean',
        ]);

        if (! empty($data['is_default']) && ! $warehouse->is_default) {
            Warehouse::where('is_default', true)->update(['is_default' => false]);
        }

        $warehouse->update($data);

        return back()->with('success', 'Warehouse updated.');
    }

    public function toggleActive(Warehouse $warehouse)
    {
        $warehouse->update(['is_active' => ! $warehouse->is_active]);
        return back()->with('success', $warehouse->is_active ? 'Warehouse activated.' : 'Warehouse deactivated.');
    }

    public function storeLocation(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'code'      => [
                'required',
                'string',
                'max:30',
                Rule::unique('warehouse_locations', 'code')->where('warehouse_id', $warehouse->id),
            ],
            'name'      => 'nullable|string|max:120',
            'type'      => 'required|in:BIN,SHELF,ZONE',
            'capacity'  => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);
        $warehouse->locations()->create($data);
        return back()->with('success', 'Location added.');
    }

    public function updateLocation(Request $request, WarehouseLocation $location)
    {
        $data = $request->validate([
            'code'      => [
                'required', 'string', 'max:30',
                Rule::unique('warehouse_locations', 'code')
                    ->where('warehouse_id', $location->warehouse_id)
                    ->ignore($location->id),
            ],
            'name'      => 'nullable|string|max:120',
            'type'      => 'required|in:BIN,SHELF,ZONE',
            'capacity'  => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);
        $location->update($data);
        return back()->with('success', 'Location updated.');
    }

    public function destroyLocation(WarehouseLocation $location)
    {
        $location->delete();
        return back()->with('success', 'Location removed.');
    }
}
