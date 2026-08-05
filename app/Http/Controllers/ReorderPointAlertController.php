<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\StockAlert;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\ReorderPointAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReorderPointAlertController extends Controller
{
    public function __construct(
        private ReorderPointAlertService $service
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
        $filters = $request->only(['status', 'warehouse_id', 'stockable_type', 'page', 'per_page']);

        return Inertia::render('Inventory/ReorderPointAlerts', [
            'alerts' => $this->service->getAlerts($filters),
            'summary' => $this->service->getSummary(),
            'settings' => $this->service->getSettings(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'filters' => $filters,
        ]);
    }

    public function api(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'warehouse_id', 'stockable_type', 'page', 'per_page']);

        return response()->json([
            'alerts' => $this->service->getAlerts($filters),
            'summary' => $this->service->getSummary(),
        ]);
    }

    public function triggerScan(): RedirectResponse
    {
        $result = $this->service->scanAndNotify();

        return redirect()->back(303)->with('success', "Scan complete: {$result['created']} new alerts, {$result['resolved']} resolved, {$result['notified']} notified.");
    }

    public function apiTriggerScan(): JsonResponse
    {
        $result = $this->service->scanAndNotify();

        return response()->json([
            'success' => true,
            'created' => $result['created'],
            'resolved' => $result['resolved'],
            'notified' => $result['notified'],
        ]);
    }

    public function acknowledge(Request $request, StockAlert $alert): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->acknowledgeAlert($alert, auth()->id(), $data['notes'] ?? null);

        return redirect()->back(303)->with('success', 'Alert acknowledged.');
    }

    public function apiAcknowledge(Request $request, StockAlert $alert): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->acknowledgeAlert($alert, auth()->id(), $data['notes'] ?? null);

        return response()->json(['success' => true, 'alert_id' => $alert->id]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'notify_emails' => ['nullable', 'string', 'max:500'],
            'notify_roles' => ['nullable', 'array'],
            'notify_roles.*' => ['string', 'in:superadmin,admin,supervisor,warehouse,finance,accounting'],
            'notify_email_enabled' => ['boolean'],
            'notify_in_app_enabled' => ['boolean'],
            'scan_frequency' => ['string', 'in:hourly,daily,weekly'],
            'reorder_multiplier' => ['integer', 'min:1', 'max:10'],
        ]);

        $this->service->updateSettings($data);

        return redirect()->back(303)->with('success', 'Reorder alert settings updated.');
    }
}
