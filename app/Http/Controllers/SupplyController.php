<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\UnitOfMeasure;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\StockStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplyController extends Controller
{
    public function index(Request $request): Response
    {
        Cache::remember('supply_status_recompute', 300, function () {
            app(StockStatusService::class)->recomputeAll();
            return true;
        });

        $supplies = Supply::query()
            ->with(['uom:id,name,abbreviation', 'stocks.warehouse:id,name,code'])
            ->when($request->search, function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('sku', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when($request->category, fn ($query, string $category) => $query->whereRaw('LOWER(category) = ?', [strtolower($category)]))
            ->when($request->stock_category && $request->stock_category !== 'all', fn ($q) => $q->where('stock_category', $request->stock_category))
            ->when($request->opex_category && $request->opex_category !== 'all', fn ($q) => $q->where('opex_category', $request->opex_category))
            ->when($request->stock_status && $request->stock_status !== 'all', function ($q) use ($request) {
                if ($request->stock_status === 'OUT_OF_STOCK') {
                    $q->whereRaw('(SELECT COALESCE(SUM(current_stock - reserved_stock), 0) FROM supply_stocks WHERE supply_stocks.supply_id = supplies.id) <= 0');
                } else {
                    $q->where('stock_status', $request->stock_status);
                }
            })
            ->when($request->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($request->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(max(1, min(500, (int) ($request->per_page ?? 25))))
            ->withQueryString();

        $lowStock = SupplyStock::query()
            ->where('reorder_point', '>', 0)
            ->whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->count();

        $recentMovements = DB::table('supply_movements as sm')
            ->leftJoin('supplies as s', 's.id', '=', 'sm.supply_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'sm.performed_by')
            ->whereNull('s.deleted_at')
            ->where('s.is_active', true)
            ->select([
                'sm.id',
                'sm.type',
                'sm.quantity',
                'sm.batch_number',
                'sm.notes',
                'sm.created_at',
                's.id as supply_id',
                's.sku as supply_sku',
                's.name as supply_name',
                'w.id as warehouse_id',
                'w.name as warehouse_name',
                'w.code as warehouse_code',
                'u.id as performer_id',
                'u.name as performer_name',
            ])
            ->latest('sm.created_at')
            ->limit(15)
            ->get()
            ->map(fn ($movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity' => (int) $movement->quantity,
                'batch_number' => $movement->batch_number,
                'notes' => $movement->notes,
                'created_at' => $movement->created_at,
                'supply' => $movement->supply_id ? [
                    'id' => $movement->supply_id,
                    'sku' => $movement->supply_sku,
                    'name' => $movement->supply_name,
                ] : null,
                'warehouse' => $movement->warehouse_id ? [
                    'id' => $movement->warehouse_id,
                    'name' => $movement->warehouse_name,
                    'code' => $movement->warehouse_code,
                ] : null,
                'performer' => $movement->performer_id ? [
                    'id' => $movement->performer_id,
                    'name' => $movement->performer_name,
                ] : null,
            ]);

        return Inertia::render('Inventory/Supplies/Index', [
            'supplies' => $supplies,
            'stats' => [
                'total'      => Supply::count(),
                'active'     => Supply::where('is_active', true)->count(),
                'low_stock'  => $lowStock,
                'trashed'    => Supply::onlyTrashed()->count(),
                'by_stock_status' => [
                    'MOVING'       => Supply::where('stock_status', 'MOVING')->count(),
                    'NON_MOVING'   => Supply::where('stock_status', 'NON_MOVING')->count(),
                    'DEAD'         => Supply::where('stock_status', 'DEAD')->count(),
                    'OUT_OF_STOCK' => Supply::whereRaw('(SELECT COALESCE(SUM(current_stock - reserved_stock), 0) FROM supply_stocks WHERE supply_stocks.supply_id = supplies.id) <= 0')->count(),
                ],
                'categories' => Supply::query()
                    ->whereNotNull('category')
                    ->where('category', '!=', '')
                    ->pluck('category')
                    ->map(fn ($c) => ucwords(strtolower(trim($c))))
                    ->unique()
                    ->sort()
                    ->values(),
            ],
            'filters' => $request->only(['search', 'category', 'status', 'stock_category', 'opex_category', 'stock_status', 'per_page']),
            'uoms' => UnitOfMeasure::where('is_active', true)->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'recent_movements' => $recentMovements,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = Supply::query()
            ->with(['uom:id,name,abbreviation', 'stocks'])
            ->when($request->search, fn ($q, $v) => $q->where(fn ($inner) =>
                $inner->where('sku', 'like', "%{$v}%")->orWhere('name', 'like', "%{$v}%")
            ))
            ->when($request->status === 'active',   fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->stock_category && $request->stock_category !== 'all',
                fn ($q, $v) => $q->where('stock_category', $v))
            ->when($request->opex_category && $request->opex_category !== 'all',
                fn ($q, $v) => $q->where('opex_category', $v))
            ->when($request->stock_status && $request->stock_status !== 'all',
                fn ($q, $v) => $q->where('stock_status', $v))
            ->orderBy('name');

        $filename = 'materials-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'SKU', 'Name', 'Category', 'Section', 'UoM',
                'Available Stock', 'Total Stock', 'Reserved Stock',
                'Unit Cost', 'Stock Value', 'Stock Status', 'Is Active',
            ]);

            $query->chunk(500, function ($supplies) use ($out): void {
                foreach ($supplies as $s) {
                    $totalStock    = $s->stocks->sum('current_stock');
                    $reservedStock = $s->stocks->sum('reserved_stock');
                    $available     = max(0, $totalStock - $reservedStock);
                    $stockValue    = $available * (float) $s->cost_price;

                    fputcsv($out, [
                        $s->sku,
                        $s->name,
                        $s->category ?? '',
                        $s->section ?? '',
                        $s->uom?->abbreviation ?? '',
                        $available,
                        (int) $totalStock,
                        (int) $reservedStock,
                        number_format((float) $s->cost_price, 2, '.', ''),
                        number_format($stockValue, 2, '.', ''),
                        $s->stock_status ?? '',
                        $s->is_active ? 'Yes' : 'No',
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function summary(Supply $supply): \Illuminate\Http\JsonResponse
    {
        $supply->load(['uom:id,name,abbreviation', 'stocks.warehouse:id,name,code']);

        $totalStock     = $supply->stocks->sum('current_stock');
        $reservedStock  = $supply->stocks->sum('reserved_stock');
        $availableStock = max(0, $totalStock - $reservedStock);

        $movements = DB::table('supply_movements as sm')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'sm.performed_by')
            ->where('sm.supply_id', $supply->id)
            ->select([
                'sm.id', 'sm.type', 'sm.quantity', 'sm.notes', 'sm.created_at',
                'w.name as warehouse_name',
                'u.name as performer_name',
            ])
            ->latest('sm.created_at')
            ->limit(10)
            ->get()
            ->map(fn ($m) => [
                'id'             => $m->id,
                'type'           => $m->type,
                'quantity'       => (int) $m->quantity,
                'notes'          => $m->notes,
                'created_at'     => $m->created_at,
                'warehouse_name' => $m->warehouse_name,
                'performer_name' => $m->performer_name,
            ]);

        return response()->json([
            'supply' => [
                'id'             => $supply->id,
                'sku'            => $supply->sku,
                'name'           => $supply->name,
                'category'       => $supply->category,
                'section'        => $supply->section,
                'stock_category' => $supply->stock_category,
                'opex_category'  => $supply->opex_category,
                'cost_price'     => $supply->cost_price,
                'reorder_point'  => $supply->reorder_point,
                'stock_status'   => $supply->stock_status,
                'is_active'      => $supply->is_active,
                'uom'            => $supply->uom,
                'description'    => $supply->description,
            ],
            'stocks' => $supply->stocks->map(fn ($s) => [
                'id'             => $s->id,
                'warehouse_name' => $s->warehouse?->name,
                'warehouse_code' => $s->warehouse?->code,
                'current_stock'  => (int) $s->current_stock,
                'reserved_stock' => (int) $s->reserved_stock,
                'available'      => max(0, (int) $s->current_stock - (int) $s->reserved_stock),
                'reorder_point'  => (int) $s->reorder_point,
            ]),
            'kpi' => [
                'total_stock'     => (int) $totalStock,
                'reserved_stock'  => (int) $reservedStock,
                'available_stock' => $availableStock,
                'reorder_point'   => (int) $supply->reorder_point,
            ],
            'recent_movements' => $movements,
        ]);
    }

    public function trash(Request $request): Response
    {
        $trashed = Supply::onlyTrashed()
            ->with(['uom:id,name,abbreviation'])
            ->when($request->search, function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('sku', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('deleted_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Supplies/Trash', [
            'trashed' => $trashed,
            'filters' => $request->only(['search']),
        ]);
    }

    public function restore(int $id): RedirectResponse
    {
        Supply::onlyTrashed()->findOrFail($id)->restore();

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');

        return back()->with('success', 'Material restored.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->normaliseCategory($this->validatedSupply($request));
        $initialStock = (int) $request->input('initial_stock', 0);
        $warehouseId = $this->warehouseId($request);

        DB::transaction(function () use ($data, $initialStock, $warehouseId, $request): void {
            $supply = Supply::create($data);

            if ($initialStock > 0 || $warehouseId !== null) {
                SupplyStock::create([
                    'supply_id'       => $supply->id,
                    'warehouse_id'    => $warehouseId,
                    'location_id'     => null,
                    'current_stock'   => $initialStock,
                    'reserved_stock'  => 0,
                    'reorder_point'   => $supply->reorder_point,
                    'last_restock_at' => $initialStock > 0 ? now() : null,
                    'last_movement_at' => $initialStock > 0 ? now() : null,
                ]);
            }

            if ($initialStock > 0) {
                $this->recordMovement($supply->id, $warehouseId, 'STOCK_IN', $initialStock, 'Initial stock', $request->user()?->id);
            }

            app(StockStatusService::class)->recompute($supply->fresh());
        });

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');

        return back()->with('success', 'Material created.');
    }

    public function update(Request $request, Supply $supply): RedirectResponse
    {
        $supply->update($this->normaliseCategory($this->validatedSupply($request, $supply)));

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');

        return back()->with('success', 'Material updated.');
    }

    public function destroy(Request $request, Supply $supply): RedirectResponse
    {
        $data = $request->validate([
            'delete_reason' => ['required', 'string', 'max:500'],
        ]);

        $supply->deleteWithReason($data['delete_reason']);

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');

        return back()->with('success', 'Material removed.');
    }

    public function updateStatus(Request $request, Supply $supply): RedirectResponse
    {
        $data = $request->validate([
            'stock_status'          => ['required', 'in:MOVING,NON_MOVING,DEAD'],
            'stock_status_override' => ['required', 'boolean'],
        ]);

        $supply->stock_status          = $data['stock_status'];
        $supply->stock_status_override = $data['stock_status_override'];
        $supply->save();

        if (! $data['stock_status_override']) {
            app(StockStatusService::class)->recompute($supply->fresh());
        }

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');

        return back()->with('success', 'Stock status updated.');
    }

    public function adjustStock(Request $request, Supply $supply): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:stock_in,stock_out,adjustment'],
            'quantity' => ['required', 'integer', 'min:0'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $warehouseId = $this->warehouseId($request);
        $quantity = (int) $data['quantity'];

        DB::transaction(function () use ($supply, $warehouseId, $quantity, $data, $request): void {
            $stock = SupplyStock::firstOrCreate(
                ['supply_id' => $supply->id, 'warehouse_id' => $warehouseId, 'location_id' => null],
                ['current_stock' => 0, 'reserved_stock' => 0, 'reorder_point' => $supply->reorder_point]
            );

            $before = (int) $stock->current_stock;
            $movementType = 'ADJUSTMENT';
            $movementQuantity = 0;

            if ($data['type'] === 'stock_in') {
                $stock->current_stock = $before + $quantity;
                $stock->last_restock_at = now();
                $movementType = 'STOCK_IN';
                $movementQuantity = $quantity;
            } elseif ($data['type'] === 'stock_out') {
                if ($quantity > $before - (int) $stock->reserved_stock) {
                    abort(422, 'Insufficient material stock.');
                }
                $stock->current_stock = $before - $quantity;
                $movementType = 'STOCK_OUT';
                $movementQuantity = -$quantity;
            } else {
                $stock->current_stock = $quantity;
                $movementQuantity = $quantity - $before;
            }

            $stock->last_movement_at = now();
            $stock->save();

            if ($movementQuantity !== 0) {
                $this->recordMovement(
                    $supply->id,
                    $warehouseId,
                    $movementType,
                    $movementQuantity,
                    $data['notes'] ?? null,
                    $request->user()?->id
                );
            }

            app(StockStatusService::class)->recompute($supply->fresh());
        });

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');

        return back()->with('success', 'Material stock updated.');
    }

    private function validatedSupply(Request $request, ?Supply $supply = null): array
    {
        $id = $supply?->id;

        return $request->validate([
            'sku'                  => ['required', 'string', 'max:60', 'unique:supplies,sku,' . ($id ?? 'NULL')],
            'name'                 => ['required', 'string', 'max:255'],
            'category'             => ['nullable', 'string', 'max:100'],
            'section'              => ['nullable', 'in:STOCK,OPEX'],
            'stock_category'       => ['nullable', 'in:RAW_MATERIAL,PRODUCTION_MATERIAL,MERCHANDISE,RD_SUPPLY'],
            'opex_category'        => ['nullable', 'in:OFFICE_SUPPLY,CLEANING_MATERIAL'],
            'stock_status'         => ['nullable', 'in:MOVING,NON_MOVING,DEAD'],
            'stock_status_override' => ['boolean'],
            'uom_id'               => ['nullable', 'integer', 'exists:units_of_measure,id'],
            'cost_price'           => ['required', 'numeric', 'min:0'],
            'min_stock_level'      => ['nullable', 'integer', 'min:0'],
            'reorder_point'        => ['nullable', 'integer', 'min:0'],
            'description'          => ['nullable', 'string'],
            'is_active'            => ['boolean'],
        ]);
    }

    private function normaliseCategory(array $data): array
    {
        if (!empty($data['category'])) {
            $data['category'] = ucwords(strtolower(trim($data['category'])));
        }
        return $data;
    }

    private function warehouseId(Request $request): ?int
    {
        if ($request->filled('warehouse_id')) {
            return (int) $request->input('warehouse_id');
        }

        return Warehouse::default()?->id;
    }

    private function recordMovement(
        int $supplyId,
        ?int $warehouseId,
        string $type,
        int $quantity,
        ?string $notes,
        ?int $performedBy
    ): void {
        DB::table('supply_movements')->insert([
            'supply_id' => $supplyId,
            'type' => $type,
            'quantity' => $quantity,
            'warehouse_id' => $warehouseId,
            'location_id' => null,
            'to_location_id' => null,
            'notes' => $notes,
            'performed_by' => $performedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
