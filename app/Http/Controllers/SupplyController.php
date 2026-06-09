<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\UnitOfMeasure;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SupplyController extends Controller
{
    public function index(Request $request): Response
    {
        $supplies = Supply::query()
            ->with(['uom:id,name,abbreviation', 'stocks.warehouse:id,name,code'])
            ->when($request->search, function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('sku', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when($request->category, fn ($query, string $category) => $query->whereRaw('LOWER(category) = ?', [strtolower($category)]))
            ->when($request->section && $request->section !== 'all', fn ($q) => $q->where('section', $request->section))
            ->when($request->stock_category && $request->stock_category !== 'all', fn ($q) => $q->where('stock_category', $request->stock_category))
            ->when($request->opex_category && $request->opex_category !== 'all', fn ($q) => $q->where('opex_category', $request->opex_category))
            ->when($request->stock_status && $request->stock_status !== 'all', fn ($q) => $q->where('stock_status', $request->stock_status))
            ->when($request->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($request->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $lowStock = SupplyStock::query()
            ->where('reorder_point', '>', 0)
            ->whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->count();

        $recentMovements = DB::table('supply_movements as sm')
            ->leftJoin('supplies as s', 's.id', '=', 'sm.supply_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'sm.performed_by')
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
                'by_section' => [
                    'STOCK' => Supply::where('section', 'STOCK')->count(),
                    'OPEX'  => Supply::where('section', 'OPEX')->count(),
                ],
                'by_stock_status' => [
                    'MOVING'     => Supply::where('stock_status', 'MOVING')->count(),
                    'NON_MOVING' => Supply::where('stock_status', 'NON_MOVING')->count(),
                    'DEAD'       => Supply::where('stock_status', 'DEAD')->count(),
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
            'filters' => $request->only(['search', 'category', 'status', 'section', 'stock_category', 'opex_category', 'stock_status']),
            'uoms' => UnitOfMeasure::where('is_active', true)->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'recent_movements' => $recentMovements,
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
        });

        return back()->with('success', 'Material created.');
    }

    public function update(Request $request, Supply $supply): RedirectResponse
    {
        $supply->update($this->normaliseCategory($this->validatedSupply($request, $supply)));

        return back()->with('success', 'Material updated.');
    }

    public function destroy(Request $request, Supply $supply): RedirectResponse
    {
        $data = $request->validate([
            'delete_reason' => ['required', 'string', 'max:500'],
        ]);

        $supply->deleteWithReason($data['delete_reason']);

        return back()->with('success', 'Material removed.');
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
        });

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
