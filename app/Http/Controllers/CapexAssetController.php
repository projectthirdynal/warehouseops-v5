<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\CapexAsset;
use App\Domain\Inventory\Models\CapexDepreciationSchedule;
use App\Domain\Inventory\Models\UnitOfMeasure;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\CapexAssetService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CapexAssetController extends Controller
{
    public function __construct(private readonly CapexAssetService $service) {}

    public function index(Request $request): Response
    {
        $assets = CapexAsset::query()
            ->with(['warehouse:id,name,code', 'assignedUser:id,name', 'uom:id,name,abbreviation'])
            ->when($request->search, function ($q, string $s): void {
                $q->where(function ($inner) use ($s): void {
                    $inner->where('asset_code', 'like', "%{$s}%")
                        ->orWhere('name', 'like', "%{$s}%");
                });
            })
            ->when($request->status && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->category && $request->category !== 'all', fn ($q) => $q->where('category', $request->category))
            ->when($request->dep_years && $request->dep_years !== 'all', fn ($q) => $q->where('depreciation_years', (int) $request->dep_years))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'total'           => CapexAsset::count(),
            'active'          => CapexAsset::where('status', 'ACTIVE')->count(),
            'disposed'        => CapexAsset::where('status', 'DISPOSED')->count(),
            'total_cost'      => CapexAsset::sum('acquisition_cost'),
            'total_book_value'=> CapexAsset::where('status', 'ACTIVE')->sum('current_book_value'),
            'due_depreciation'=> CapexDepreciationSchedule::where('is_posted', false)
                ->whereYear('depreciation_date', now()->year)
                ->count(),
        ];

        return Inertia::render('Inventory/Assets/Index', [
            'assets'     => $assets,
            'stats'      => $stats,
            'filters'    => $request->only(['search', 'status', 'category', 'dep_years']),
            'categories' => CapexAsset::CATEGORIES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Assets/Create', [
            'categories' => CapexAsset::CATEGORIES,
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'uoms'       => UnitOfMeasure::where('is_active', true)->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'users'      => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $asset = $this->service->create($data, $request->user()->id);

        return redirect()->route('inventory.assets.show', $asset->id)
            ->with('success', 'Asset created.');
    }

    public function show(CapexAsset $asset): Response
    {
        $asset->load([
            'depreciationSchedule',
            'assignments.assignedUser:id,name',
            'assignments.assignedByUser:id,name',
            'warehouse:id,name,code',
            'assignedUser:id,name',
            'createdBy:id,name',
            'uom:id,name,abbreviation',
        ]);

        return Inertia::render('Inventory/Assets/Show', [
            'asset' => $asset,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(CapexAsset $asset): Response
    {
        return Inertia::render('Inventory/Assets/Create', [
            'asset'      => $asset,
            'categories' => CapexAsset::CATEGORIES,
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'uoms'       => UnitOfMeasure::where('is_active', true)->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'users'      => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, CapexAsset $asset): RedirectResponse
    {
        $data = $this->validated($request, $asset);
        $this->service->update($asset, $data);

        return back()->with('success', 'Asset updated.');
    }

    public function assign(Request $request, CapexAsset $asset): RedirectResponse
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'department'  => ['nullable', 'string', 'max:100'],
            'location'    => ['nullable', 'string', 'max:200'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        $this->service->assign($asset, (int) $data['assigned_to'], $request->user()->id, $data);

        return back()->with('success', 'Asset assigned.');
    }

    public function postDepreciation(Request $request, CapexDepreciationSchedule $schedule): RedirectResponse
    {
        if ($schedule->is_posted) {
            return back()->with('error', 'Depreciation row already posted.');
        }

        $this->service->postDepreciation($schedule, $request->user()->id);

        return back()->with('success', 'Depreciation posted.');
    }

    public function dispose(Request $request, CapexAsset $asset): RedirectResponse
    {
        $data = $request->validate([
            'disposal_reason' => ['required', 'string', 'max:500'],
            'disposal_value'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->service->dispose($asset, $data);

        return back()->with('success', 'Asset disposed.');
    }

    private function validated(Request $request, ?CapexAsset $asset = null): array
    {
        return $request->validate([
            'asset_code'        => ['required', 'string', 'max:60', 'unique:capex_assets,asset_code,' . ($asset?->id ?? 'NULL')],
            'name'              => ['required', 'string', 'max:255'],
            'description'       => ['nullable', 'string'],
            'category'          => ['required', 'in:' . implode(',', array_keys(CapexAsset::CATEGORIES))],
            'depreciation_years'=> ['required', 'integer', 'in:1,2,3'],
            'purchase_date'     => ['required', 'date'],
            'acquisition_cost'  => ['required', 'numeric', 'min:0'],
            'salvage_value'     => ['nullable', 'numeric', 'min:0'],
            'warehouse_id'      => ['nullable', 'integer', 'exists:warehouses,id'],
            'assigned_to'       => ['nullable', 'integer', 'exists:users,id'],
            'department'        => ['nullable', 'string', 'max:100'],
            'uom_id'            => ['nullable', 'integer', 'exists:units_of_measure,id'],
            'quantity'          => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
