<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\UnitOfMeasure;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Procurement\Enums\PoStatus;
use App\Domain\Procurement\Enums\PrStatus;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\Supplier;
use App\Domain\Procurement\Services\ProcurementService;
use App\Domain\Product\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly ProcurementService $procurement) {}

    public function index(Request $request)
    {
        $pos = PurchaseOrder::query()
            ->with(['supplier:id,name,code', 'warehouse:id,name,code', 'creator:id,name'])
            ->withCount('items')
            ->when($request->status,    fn ($q, $v) => $q->where('status', $v))
            ->when($request->supplier,  fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->search,    fn ($q, $v) => $q->where('po_number', 'ILIKE', "%{$v}%"))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'draft'     => PurchaseOrder::where('status', PoStatus::DRAFT)->count(),
            'sent'      => PurchaseOrder::where('status', PoStatus::SENT)->count(),
            'partial'   => PurchaseOrder::where('status', PoStatus::PARTIALLY_RECEIVED)->count(),
            'received'  => PurchaseOrder::where('status', PoStatus::RECEIVED)->count(),
        ];

        return Inertia::render('Procurement/Orders/Index', [
            'orders'    => $pos,
            'stats'     => $stats,
            'suppliers' => Supplier::active()->select('id', 'name')->orderBy('name')->get(),
            'filters'   => $request->only(['status', 'supplier', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        $pr = $request->pr_id ? PurchaseRequest::with('items.product')->find($request->pr_id) : null;

        return Inertia::render('Procurement/Orders/Create', [
            'suppliers'  => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'payment_terms']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'products'   => Product::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'cost_price']),
            'supplies'   => Supply::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'cost_price']),
            'uoms'       => UnitOfMeasure::where('is_active', true)->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'pr'         => $pr,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pr_id'                  => 'nullable|integer|exists:purchase_requests,id',
            'supplier_id'            => 'required|integer|exists:suppliers,id',
            'warehouse_id'           => 'required|integer|exists:warehouses,id',
            'payment_terms'          => 'nullable|string|max:60',
            'expected_delivery_date' => 'nullable|date',
            'currency_code'          => 'required|string|size:3',
            'exchange_rate'          => 'required|numeric|min:0.000001',
            'tax_amount'             => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string|max:1000',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'nullable|integer|exists:products,id',
            'items.*.supply_id'      => 'nullable|integer|exists:supplies,id',
            'items.*.uom_id'         => 'nullable|integer|exists:units_of_measure,id',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.unit_price'     => 'required|numeric|min:0',
            'items.*.tax_rate'       => 'nullable|numeric|min:0|max:100',
        ]);

        $po = DB::transaction(function () use ($data, $request) {
            $po = PurchaseOrder::create([
                'po_number'              => PurchaseOrder::generateNumber(),
                'pr_id'                  => $data['pr_id'] ?? null,
                'supplier_id'            => $data['supplier_id'],
                'warehouse_id'           => $data['warehouse_id'],
                'payment_terms'          => $data['payment_terms'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'status'                 => PoStatus::DRAFT,
                'currency_code'          => $data['currency_code'],
                'exchange_rate'          => $data['exchange_rate'],
                'exchange_rate_date'     => now()->toDateString(),
                'tax_amount'             => $data['tax_amount'] ?? 0,
                'notes'                  => $data['notes'] ?? null,
                'created_by'             => $request->user()->id,
            ]);

            $subtotal = 0;
            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) && empty($item['supply_id'])) continue;
                $lineSubtotal = (int) $item['quantity_ordered'] * (float) $item['unit_price'];
                $taxRate      = (float) ($item['tax_rate'] ?? 0);
                $lineTotal    = $lineSubtotal * (1 + $taxRate / 100);
                $subtotal    += $lineSubtotal;

                $po->items()->create([
                    'product_id'       => $item['product_id'] ?? null,
                    'supply_id'        => $item['supply_id'] ?? null,
                    'uom_id'           => $item['uom_id'] ?? null,
                    'quantity_ordered' => $item['quantity_ordered'],
                    'unit_price'       => $item['unit_price'],
                    'tax_rate'         => $taxRate,
                    'line_total'       => $lineTotal,
                ]);
            }
            $po->subtotal     = $subtotal;
            $po->total_amount = $subtotal + (float) $po->tax_amount;
            $po->save();

            return $po;
        });

        return redirect()->route('procurement.orders.show', $po)->with('success', 'Purchase order created.');
    }

    public function show(PurchaseOrder $order)
    {
        $order->load([
            'items.product:id,sku,name',
            'supplier:id,name,code,contact_person,email,phone,payment_terms',
            'warehouse:id,name,code',
            'creator:id,name',
            'approver:id,name',
            'receivingReports' => fn ($q) => $q->with('items', 'receiver:id,name')->latest(),
            'purchaseRequest:id,pr_number',
        ]);

        return Inertia::render('Procurement/Orders/Show', [
            'po' => $order,
        ]);
    }

    public function send(PurchaseOrder $order)
    {
        $this->procurement->sendPo($order);
        return back()->with('success', 'PO sent to supplier.');
    }

    public function cancel(Request $request, PurchaseOrder $order)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->procurement->cancelPo($order, $data['reason']);
        return back()->with('success', 'PO cancelled.');
    }
}
