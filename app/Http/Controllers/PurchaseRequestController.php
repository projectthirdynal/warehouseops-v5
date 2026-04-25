<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\UnitOfMeasure;
use App\Domain\Procurement\Enums\PrStatus;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Services\ProcurementService;
use App\Domain\Product\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PurchaseRequestController extends Controller
{
    public function __construct(private readonly ProcurementService $procurement) {}

    public function index(Request $request)
    {
        $prs = PurchaseRequest::query()
            ->with(['requester:id,name', 'approver:id,name'])
            ->withCount('items')
            ->when($request->status,           fn ($q, $v) => $q->where('status', $v))
            ->when($request->priority,         fn ($q, $v) => $q->where('priority', $v))
            ->when($request->search,           fn ($q, $v) => $q->where('pr_number', 'ILIKE', "%{$v}%"))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'draft'     => PurchaseRequest::where('status', PrStatus::DRAFT)->count(),
            'submitted' => PurchaseRequest::where('status', PrStatus::SUBMITTED)->count(),
            'approved'  => PurchaseRequest::where('status', PrStatus::APPROVED)->count(),
            'converted' => PurchaseRequest::where('status', PrStatus::CONVERTED)->count(),
        ];

        return Inertia::render('Procurement/Requests/Index', [
            'requests' => $prs,
            'stats'    => $stats,
            'filters'  => $request->only(['status', 'priority', 'search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Procurement/Requests/Create', [
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'cost_price']),
            'supplies' => Supply::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'cost_price']),
            'uoms'     => UnitOfMeasure::where('is_active', true)->orderBy('name')->get(['id', 'name', 'abbreviation']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'department'                 => 'nullable|string|max:60',
            'priority'                   => 'required|in:LOW,NORMAL,URGENT',
            'reason'                     => 'nullable|string|max:1000',
            'needed_by_date'             => 'nullable|date|after_or_equal:today',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer|exists:products,id',
            'items.*.supply_id'          => 'nullable|integer|exists:supplies,id',
            'items.*.uom_id'             => 'nullable|integer|exists:units_of_measure,id',
            'items.*.quantity_requested' => 'required|integer|min:1',
            'items.*.unit_price_estimate'=> 'nullable|numeric|min:0',
            'items.*.notes'              => 'nullable|string|max:300',
        ]);

        $pr = DB::transaction(function () use ($data, $request) {
            $estimated = collect($data['items'])->sum(fn ($i) =>
                ((float) ($i['unit_price_estimate'] ?? 0)) * ((int) $i['quantity_requested'])
            );

            $pr = PurchaseRequest::create([
                'pr_number'        => PurchaseRequest::generateNumber(),
                'requested_by'     => $request->user()->id,
                'department'       => $data['department'] ?? null,
                'priority'         => $data['priority'],
                'reason'           => $data['reason'] ?? null,
                'needed_by_date'   => $data['needed_by_date'] ?? null,
                'status'           => PrStatus::DRAFT,
                'estimated_total'  => $estimated,
            ]);

            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) && empty($item['supply_id'])) continue;
                $pr->items()->create([
                    'product_id'         => $item['product_id'] ?? null,
                    'supply_id'          => $item['supply_id'] ?? null,
                    'uom_id'             => $item['uom_id'] ?? null,
                    'quantity_requested' => $item['quantity_requested'],
                    'unit_price_estimate'=> $item['unit_price_estimate'] ?? 0,
                    'notes'              => $item['notes'] ?? null,
                ]);
            }
            return $pr;
        });

        return redirect()->route('procurement.requests.show', $pr)->with('success', 'Purchase request created.');
    }

    public function show(PurchaseRequest $request)
    {
        $request->load(['items.product:id,sku,name', 'requester:id,name', 'approver:id,name']);
        return Inertia::render('Procurement/Requests/Show', [
            'pr' => $request,
        ]);
    }

    public function submit(PurchaseRequest $request)
    {
        $this->procurement->submitPr($request);
        return back()->with('success', 'Purchase request submitted.');
    }

    public function approve(Request $http, PurchaseRequest $request)
    {
        $this->procurement->approvePr($request, $http->user());
        return back()->with('success', 'Purchase request approved.');
    }

    public function reject(Request $http, PurchaseRequest $request)
    {
        $data = $http->validate(['reason' => 'required|string|max:500']);
        $this->procurement->rejectPr($request, $http->user(), $data['reason']);
        return back()->with('success', 'Purchase request rejected.');
    }
}
