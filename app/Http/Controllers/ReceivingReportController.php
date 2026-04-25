<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\WarehouseLocation;
use App\Domain\Procurement\Enums\GrnStatus;
use App\Domain\Procurement\Enums\PoStatus;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\ReceivingReport;
use App\Domain\Procurement\Services\ProcurementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReceivingReportController extends Controller
{
    public function __construct(private readonly ProcurementService $procurement) {}

    public function index(Request $request)
    {
        $grns = ReceivingReport::query()
            ->with(['purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name', 'warehouse:id,name', 'receiver:id,name'])
            ->withCount('items')
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->search, fn ($q, $v) => $q->where('grn_number', 'ILIKE', "%{$v}%"))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Procurement/Receiving/Index', [
            'grns'        => $grns,
            'open_pos'    => PurchaseOrder::whereIn('status', [PoStatus::SENT, PoStatus::PARTIALLY_RECEIVED])
                ->with('supplier:id,name')
                ->orderBy('expected_delivery_date')
                ->get(['id', 'po_number', 'supplier_id', 'expected_delivery_date', 'status']),
            'filters'     => $request->only(['status', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        $request->validate(['po_id' => 'required|integer|exists:purchase_orders,id']);

        $po = PurchaseOrder::with(['items.product:id,sku,name', 'supplier:id,name'])
            ->findOrFail($request->po_id);

        if (! in_array($po->status, [PoStatus::SENT, PoStatus::PARTIALLY_RECEIVED], true)) {
            return back()->with('error', 'PO is not open for receiving.');
        }

        return Inertia::render('Procurement/Receiving/Create', [
            'po'        => $po,
            'locations' => WarehouseLocation::where('warehouse_id', $po->warehouse_id)
                ->where('is_active', true)
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'po_id'                       => 'required|integer|exists:purchase_orders,id',
            'location_id'                 => 'nullable|integer|exists:warehouse_locations,id',
            'notes'                       => 'nullable|string|max:1000',
            'discrepancy_notes'           => 'nullable|string|max:1000',
            'items'                       => 'required|array|min:1',
            'items.*.po_item_id'          => 'required|integer|exists:purchase_order_items,id',
            'items.*.quantity_received'   => 'required|integer|min:0',
            'items.*.quantity_rejected'   => 'nullable|integer|min:0',
            'items.*.condition'           => 'required|in:GOOD,DAMAGED,EXPIRED',
            'items.*.batch_number'        => 'nullable|string|max:60',
            'items.*.expiry_date'         => 'nullable|date',
            'items.*.rejection_reason'    => 'nullable|string|max:200',
            'items.*.notes'               => 'nullable|string|max:300',
        ]);

        $po = PurchaseOrder::findOrFail($data['po_id']);
        if (! in_array($po->status, [PoStatus::SENT, PoStatus::PARTIALLY_RECEIVED], true)) {
            return back()->with('error', 'PO not open for receiving.');
        }

        $grn = DB::transaction(function () use ($data, $po, $request) {
            $grn = ReceivingReport::create([
                'grn_number'         => ReceivingReport::generateNumber(),
                'po_id'              => $po->id,
                'warehouse_id'       => $po->warehouse_id,
                'location_id'        => $data['location_id'] ?? null,
                'received_by'        => $request->user()->id,
                'received_at'        => now(),
                'exchange_rate'      => $po->exchange_rate,
                'exchange_rate_date' => $po->exchange_rate_date,
                'status'             => GrnStatus::DRAFT,
                'notes'              => $data['notes'] ?? null,
                'discrepancy_notes'  => $data['discrepancy_notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                if ((int) $item['quantity_received'] === 0 && (int) ($item['quantity_rejected'] ?? 0) === 0) continue;
                $grn->items()->create([
                    'po_item_id'        => $item['po_item_id'],
                    'quantity_received' => (int) $item['quantity_received'],
                    'quantity_rejected' => (int) ($item['quantity_rejected'] ?? 0),
                    'condition'         => $item['condition'],
                    'batch_number'      => $item['batch_number'] ?? null,
                    'expiry_date'       => $item['expiry_date'] ?? null,
                    'rejection_reason'  => $item['rejection_reason'] ?? null,
                    'notes'             => $item['notes'] ?? null,
                ]);
            }
            return $grn;
        });

        return redirect()->route('procurement.receiving.show', $grn)->with('success', 'GRN draft created. Confirm to post stock.');
    }

    public function show(ReceivingReport $receiving)
    {
        $receiving->load([
            'items.purchaseOrderItem.product:id,sku,name',
            'purchaseOrder.supplier:id,name',
            'warehouse:id,name',
            'location:id,code,name',
            'receiver:id,name',
        ]);
        return Inertia::render('Procurement/Receiving/Show', ['grn' => $receiving]);
    }

    public function confirm(ReceivingReport $receiving)
    {
        $this->procurement->confirmGrn($receiving);
        return back()->with('success', 'GRN confirmed; stock posted.');
    }
}
