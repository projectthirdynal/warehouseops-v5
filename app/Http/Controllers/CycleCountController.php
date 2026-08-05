<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\CycleCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class CycleCountController extends Controller
{
    public function __construct(
        private readonly CycleCountService $service
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()?->role, [
                'superadmin', 'admin', 'supervisor', 'warehouse',
            ])) {
                abort(403, 'Access denied');
            }

            return $next($request);
        });
    }

    public function index(): Response
    {
        return Inertia::render('Inventory/CycleCounts', [
            'dashboard' => $this->service->getDashboard(),
        ]);
    }

    public function api(): JsonResponse
    {
        return response()->json($this->service->getDashboard());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'sample_size' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $this->service->createSession($data['warehouse_id'], $data['sample_size'], $request->user()?->id);

        return back()->with('success', 'Cycle count session created.');
    }

    public function show(int $id): Response
    {
        return Inertia::render('Inventory/CycleCountDetail', [
            'detail' => $this->service->getSession($id),
        ]);
    }

    public function apiShow(int $id): JsonResponse
    {
        return response()->json($this->service->getSession($id));
    }

    public function recordCount(Request $request, int $itemId): RedirectResponse
    {
        $data = $request->validate([
            'counted_qty' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->recordCount($itemId, $data['counted_qty'], $request->user()?->id, $data['notes'] ?? null);

        return back()->with('success', 'Count recorded.');
    }

    public function apiRecordCount(Request $request, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'counted_qty' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $item = $this->service->recordCount($itemId, $data['counted_qty'], $request->user()?->id, $data['notes'] ?? null);

        return response()->json(['success' => true, 'item' => $item]);
    }

    public function skipItem(int $itemId): RedirectResponse
    {
        $this->service->skipItem($itemId);

        return back()->with('success', 'Item skipped.');
    }

    public function finalize(Request $request, int $id): RedirectResponse
    {
        try {
            $result = $this->service->finalizeSession($id, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Session finalized. {$result['adjustments_created']} adjustment(s) created for review.");
    }

    public function cancel(int $id): RedirectResponse
    {
        try {
            $this->service->cancelSession($id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Session cancelled.');
    }

    public function report(Request $request): Response
    {
        $filters = $request->only(['from', 'to', 'warehouse_id']);

        return Inertia::render('Inventory/CycleCountReport', [
            'report' => $this->service->varianceReport($filters['from'] ?? null, $filters['to'] ?? null, $filters['warehouse_id'] ?? null),
            'filters' => $filters,
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_generate_enabled' => ['boolean'],
            'frequency' => ['string', 'in:daily,weekly,monthly'],
            'sample_size' => ['integer', 'min:1', 'max:200'],
            'auto_create_adjustments' => ['boolean'],
        ]);

        $this->service->updateSettings($data);

        return back()->with('success', 'Cycle count settings updated.');
    }
}
