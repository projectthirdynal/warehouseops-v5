<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryValuationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryValuationController extends Controller
{
    public function __construct(
        private InventoryValuationService $service
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
        $filters = $this->parseFilters($request);

        return Inertia::render('Inventory/InventoryValuation', [
            'valuation' => $this->service->getValuation($filters),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'filters' => $filters,
        ]);
    }

    public function api(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);

        return response()->json($this->service->getValuation($filters));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $this->parseFilters($request);
        $method = $filters['method'] ?? 'FIFO';

        $csv = $this->service->exportCsv($filters);
        $filename = 'inventory_valuation_'.strtolower($method).'_'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFilters(Request $request): array
    {
        return [
            'method' => $request->input('method', 'FIFO'),
            'warehouse_id' => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
            'stream' => $request->input('stream', 'all'),
            'search' => $request->filled('search') ? $request->input('search') : null,
            'page' => $request->filled('page') ? (int) $request->input('page') : 1,
            'per_page' => $request->filled('per_page') ? (int) $request->input('per_page') : 50,
        ];
    }
}
