<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\StockAlert;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\RealTimeStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockDashboardController extends Controller
{
    public function __construct(
        private RealTimeStockService $stockService
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()?->role, [
                'superadmin', 'admin', 'supervisor', 'warehouse', 'finance', 'accounting',
            ])) {
                abort(403, 'Access denied');
            }

            return $next($request);
        });
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['warehouse_id', 'alert_type']);

        return Inertia::render('Inventory/StockDashboard', [
            'data' => $this->stockService->getDashboardData($filters),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'filters' => $filters,
        ]);
    }

    public function api(Request $request): JsonResponse
    {
        $filters = $request->only(['warehouse_id', 'alert_type']);

        return response()->json($this->stockService->getDashboardData($filters));
    }

    public function syncAlerts(): JsonResponse
    {
        $result = $this->stockService->syncAlerts();

        return response()->json([
            'success' => true,
            'created' => $result['created'],
            'resolved' => $result['resolved'],
        ]);
    }

    public function acknowledgeAlert(Request $request, StockAlert $alert): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->stockService->acknowledgeAlert($alert, auth()->id(), $data['notes'] ?? null);

        return response()->json(['success' => true, 'alert_id' => $alert->id]);
    }
}
