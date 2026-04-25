<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WarehouseController extends Controller
{
    public function index()
    {
        $warehouses = Warehouse::with(['locations' => fn ($q) => $q->orderBy('code')])
            ->orderBy('name')
            ->get();
        return Inertia::render('Inventory/Warehouses/Index', [
            'warehouses' => $warehouses,
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

    public function storeLocation(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'code'      => 'required|string|max:30',
            'name'      => 'nullable|string|max:120',
            'type'      => 'required|in:BIN,SHELF,ZONE',
            'capacity'  => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);
        $warehouse->locations()->create($data);
        return back()->with('success', 'Location added.');
    }

    public function destroyLocation(WarehouseLocation $location)
    {
        $location->delete();
        return back()->with('success', 'Location removed.');
    }
}
