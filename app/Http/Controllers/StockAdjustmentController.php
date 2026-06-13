<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Product\Models\InventoryMovement;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use App\Notifications\StockAdjustmentNotification;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class StockAdjustmentController extends Controller
{
    public function __construct(private readonly ApprovalService $approval) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Inventory/StockAdjustments', [
            'adjustments' => $this->adjustmentQuery($request)->paginate(25)->withQueryString()->through(fn ($a) => $this->flattenAdjustment($a)),
            'warehouses'  => Warehouse::select('id', 'name', 'code')->orderBy('name')->get(),
            'products'    => Product::where('is_active', true)->select('id', 'name', 'sku')->orderBy('name')->get(),
            'supplies'    => Supply::where('is_active', true)->select('id', 'name', 'sku')->orderBy('name')->get(),
            'stats'       => [
                'pending'  => StockAdjustment::where('status', 'PENDING')->count(),
                'approved' => StockAdjustment::where('status', 'APPROVED')->count(),
                'rejected' => StockAdjustment::where('status', 'REJECTED')->count(),
            ],
            'filters' => $request->only(['status', 'warehouse_id']),
        ]);
    }

    public function report(Request $request): Response
    {
        return Inertia::render('Inventory/AdjustmentReport', [
            'adjustments' => $this->adjustmentQuery($request)
                ->when($request->from, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
                ->when($request->to, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
                ->paginate(50)
                ->withQueryString()
                ->through(fn ($a) => $this->flattenAdjustment($a)),
            'warehouses' => Warehouse::select('id', 'name', 'code')->orderBy('name')->get(),
            'summary' => [
                'total' => StockAdjustment::count(),
                'pending' => StockAdjustment::where('status', 'PENDING')->count(),
                'approved' => StockAdjustment::where('status', 'APPROVED')->count(),
                'rejected' => StockAdjustment::where('status', 'REJECTED')->count(),
            ],
            'filters' => $request->only(['from', 'to', 'status', 'warehouse_id']),
        ]);
    }

    public function downloadReport(): RedirectResponse
    {
        return back()->with('info', 'Export feature coming soon.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id'     => ['nullable', 'required_without:supply_id', 'exists:products,id'],
            'supply_id'      => ['nullable', 'required_without:product_id', 'exists:supplies,id'],
            'variant_id'     => ['nullable', 'exists:product_variants,id'],
            'warehouse_id'   => ['required', 'exists:warehouses,id'],
            'reason_code'    => ['required', 'string', 'max:50'],
            'reason_notes'   => ['nullable', 'string', 'max:1000'],
            'quantity_after' => ['required', 'integer', 'min:0'],
        ]);

        $adj = null;
        DB::transaction(function () use ($data, $request, &$adj): void {
            $quantityBefore = $this->currentQuantity($data);
            $quantityAfter  = (int) $data['quantity_after'];

            $adj = StockAdjustment::create([
                'product_id'      => $data['product_id'] ?? null,
                'supply_id'       => $data['supply_id'] ?? null,
                'variant_id'      => $data['variant_id'] ?? null,
                'warehouse_id'    => $data['warehouse_id'],
                'reason_code'     => $data['reason_code'],
                'reason_notes'    => $data['reason_notes'] ?? null,
                'quantity_before' => $quantityBefore,
                'quantity_after'  => $quantityAfter,
                'variance'        => $quantityAfter - $quantityBefore,
                'status'          => 'PENDING',
                'submitted_by'    => $request->user()?->id,
            ]);
        });

        if ($adj) {
            $adj->load(['product', 'supply', 'warehouse']);
            $approvers = $this->approval->getApprovers('adjustment');
            Notification::send($approvers, new StockAdjustmentNotification($adj, 'submitted'));
        }

        return back()->with('success', 'Adjustment submitted for approval.');
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $adjustment = StockAdjustment::findOrFail($id);

        if ($adjustment->status !== 'PENDING') {
            return back()->with('error', 'Adjustment already processed.');
        }

        DB::transaction(function () use ($adjustment, $request): void {
            $adjustment->update([
                'status' => 'APPROVED',
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
            ]);

            if ($adjustment->supply_id) {
                SupplyStock::firstOrCreate(
                    [
                        'supply_id'    => $adjustment->supply_id,
                        'warehouse_id' => $adjustment->warehouse_id,
                        'location_id'  => $adjustment->location_id,
                    ],
                    ['current_stock' => 0, 'reserved_stock' => 0, 'reorder_point' => 10]
                )->update(['current_stock' => $adjustment->quantity_after, 'last_movement_at' => now()]);
            } else {
                ProductStock::firstOrCreate(
                    [
                        'product_id'   => $adjustment->product_id,
                        'variant_id'   => $adjustment->variant_id,
                        'warehouse_id' => $adjustment->warehouse_id,
                        'location_id'  => $adjustment->location_id,
                    ],
                    ['current_stock' => 0, 'reserved_stock' => 0, 'reorder_point' => 10]
                )->update(['current_stock' => $adjustment->quantity_after, 'last_movement_at' => now()]);
            }

            // Write the movement ledger entry so the Movements page reflects this
            if ($adjustment->supply_id) {
                \Illuminate\Support\Facades\DB::table('supply_movements')->insert([
                    'supply_id'      => $adjustment->supply_id,
                    'warehouse_id'   => $adjustment->warehouse_id,
                    'type'           => 'ADJUSTMENT',
                    'quantity'       => $adjustment->variance,
                    'reference_type' => StockAdjustment::class,
                    'reference_id'   => $adjustment->id,
                    'notes'          => '[' . $adjustment->reason_code . '] ' . ($adjustment->reason_notes ?? ''),
                    'performed_by'   => $request->user()?->id,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            } else {
                InventoryMovement::create([
                    'product_id'     => $adjustment->product_id,
                    'variant_id'     => $adjustment->variant_id,
                    'warehouse_id'   => $adjustment->warehouse_id,
                    'location_id'    => $adjustment->location_id,
                    'type'           => 'ADJUSTMENT',
                    'quantity'       => $adjustment->variance,
                    'reference_type' => StockAdjustment::class,
                    'reference_id'   => $adjustment->id,
                    'notes'          => '[' . $adjustment->reason_code . '] ' . ($adjustment->reason_notes ?? ''),
                    'performed_by'   => $request->user()?->id,
                ]);
            }
        });

        Cache::forget('inv_dashboard_stats');
        Cache::forget('inv_dashboard_charts');

        $adjustment->load(['product', 'supply', 'warehouse']);
        if ($adjustment->submittedBy) {
            $adjustment->submittedBy->notify(new StockAdjustmentNotification($adjustment, 'approved'));
        }

        return back()->with('success', 'Adjustment approved and stock updated.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $adjustment = StockAdjustment::findOrFail($id);

        if ($adjustment->status !== 'PENDING') {
            return back()->with('error', 'Adjustment already processed.');
        }

        $adjustment->update([
            'status' => 'REJECTED',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'reason_notes' => trim(($adjustment->reason_notes ?? '') . "\n[REJECTED] " . ($request->input('reason') ?? 'No reason provided')),
        ]);

        $adjustment->load(['product', 'supply', 'warehouse']);
        if ($adjustment->submittedBy) {
            $adjustment->submittedBy->notify(new StockAdjustmentNotification($adjustment, 'rejected'));
        }

        return back()->with('success', 'Adjustment rejected.');
    }

    private function adjustmentQuery(Request $request)
    {
        return StockAdjustment::with(['product', 'variant', 'supply', 'warehouse', 'submittedBy', 'approvedBy'])
            ->when($request->status, fn ($query, string $status) => $query->where('status', $status))
            ->when($request->warehouse_id, fn ($query, string $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->orderByDesc('created_at');
    }

    private function flattenAdjustment(StockAdjustment $a): array
    {
        return [
            'id'              => $a->id,
            'reason_code'     => $a->reason_code,
            'reason_notes'    => $a->reason_notes,
            'quantity_before' => $a->quantity_before,
            'quantity_after'  => $a->quantity_after,
            'variance'        => $a->variance,
            'status'          => $a->status,
            'created_at'      => $a->created_at,
            'approved_at'     => $a->approved_at,
            'product_name'    => $a->product?->name,
            'product_sku'     => $a->product?->sku,
            'supply_name'     => $a->supply?->name,
            'supply_sku'      => $a->supply?->sku,
            'warehouse_name'  => $a->warehouse?->name,
            'warehouse_code'  => $a->warehouse?->code,
            'submitted_by'    => $a->submittedBy?->name,
            'approved_by'     => $a->approvedBy?->name,
        ];
    }

    private function currentQuantity(array $data): int
    {
        if (! empty($data['supply_id'])) {
            return (int) (SupplyStock::where('supply_id', $data['supply_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->value('current_stock') ?? 0);
        }

        return (int) (ProductStock::where('product_id', $data['product_id'] ?? null)
            ->where('variant_id', $data['variant_id'] ?? null)
            ->where('warehouse_id', $data['warehouse_id'])
            ->value('current_stock') ?? 0);
    }
}
