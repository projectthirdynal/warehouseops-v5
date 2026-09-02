<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Modules\Inventory\Models\MovementAuditTrail;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\MovementAuditTrailService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MovementAuditTrailController extends Controller
{
    public function __construct(
        private MovementAuditTrailService $auditService
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
        $filters = $request->only(['warehouse_id', 'movement_type', 'stream', 'search']);

        return Inertia::render('Inventory/MovementAuditTrail', [
            'data' => $this->getLedger($filters),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'filters' => $filters,
        ]);
    }

    public function api(Request $request): JsonResponse
    {
        $filters = $request->only(['warehouse_id', 'movement_type', 'stream', 'search']);

        return response()->json($this->getLedger($filters));
    }

    public function itemTrail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stockable_type' => ['required', 'in:product,supply'],
            'stockable_id' => ['required', 'integer', 'min:1'],
        ]);

        $type = $data['stockable_type'] === 'product'
            ? 'App\\Domain\\Product\\Models\\Product'
            : 'App\\Domain\\Inventory\\Models\\Supply';

        $rows = MovementAuditTrail::with(['warehouse', 'performer'])
            ->where('stockable_type', $type)
            ->where('stockable_id', $data['stockable_id'])
            ->latest()
            ->paginate(50);

        return response()->json($this->serialize($rows));
    }

    public function backfill(): JsonResponse
    {
        $created = $this->auditService->backfill();

        return response()->json(['success' => true, 'created' => $created]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function getLedger(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;
        $movementType = $filters['movement_type'] ?? null;
        $stream = $filters['stream'] ?? null;
        $search = $filters['search'] ?? null;

        $query = MovementAuditTrail::with(['stockable', 'warehouse', 'performer']);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($movementType) {
            $query->where('type', $movementType);
        }

        if ($stream) {
            $query->where('stockable_type', $stream === 'product'
                ? 'App\\Domain\\Product\\Models\\Product'
                : 'App\\Domain\\Inventory\\Models\\Supply');
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHasMorph('stockable', [
                    'App\\Domain\\Product\\Models\\Product',
                    'App\\Domain\\Inventory\\Models\\Supply',
                ], function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                })
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('reason_notes', 'like', "%{$search}%");
            });
        }

        $perPage = min(100, max(10, (int) request()->input('per_page', 25)));

        $paginated = $query->latest()->paginate($perPage)->withQueryString();

        $summary = DB::table('movement_audit_trails')
            ->select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->all();

        return [
            'audits' => $this->serialize($paginated),
            'summary' => $summary,
            'filters' => $filters,
        ];
    }

    /**
     * @param  LengthAwarePaginator<MovementAuditTrail>  $paginated
     * @return array<string, mixed>
     */
    private function serialize($paginated): array
    {
        return [
            'data' => $paginated->items(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'links' => $paginated->linkCollection()->toArray(),
        ];
    }
}
