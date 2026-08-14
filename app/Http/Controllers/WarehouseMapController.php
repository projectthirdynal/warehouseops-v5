<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Services\WarehouseMapService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WarehouseMapController extends Controller
{
    public function __construct(
        private readonly WarehouseMapService $mapService,
    ) {}

    public function index()
    {
        $overview = $this->mapService->getAllWarehousesOverview();

        return Inertia::render('Inventory/WarehouseMap', [
            'overview' => $overview,
        ]);
    }

    public function apiWarehouseMap(int $warehouseId)
    {
        $data = $this->mapService->getWarehouseMap($warehouseId);

        return response()->json($data);
    }

    public function apiLocationDetails(int $locationId)
    {
        $data = $this->mapService->getLocationDetails($locationId);

        return response()->json($data);
    }

    public function apiUpdateCoordinates(Request $request, int $locationId)
    {
        $data = $request->validate([
            'row_index' => 'required|integer|min:0',
            'col_index' => 'required|integer|min:0',
            'zone_color' => 'nullable|string|max:20',
        ]);

        $loc = $this->mapService->updateLocationCoordinates(
            $locationId,
            $data['row_index'],
            $data['col_index'],
            $data['zone_color'] ?? null,
        );

        return response()->json([
            'id' => $loc->id,
            'row_index' => $loc->row_index,
            'col_index' => $loc->col_index,
            'zone_color' => $loc->zone_color,
        ]);
    }
}
