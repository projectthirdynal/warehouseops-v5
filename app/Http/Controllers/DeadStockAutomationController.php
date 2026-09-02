<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Modules\Inventory\Services\DeadStockScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeadStockAutomationController extends Controller
{
    public function __construct(
        private DeadStockScanService $service
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()?->role, [
                'superadmin', 'admin', 'supervisor', 'warehouse', 'finance',
            ])) {
                abort(403, 'Access denied');
            }

            return $next($request);
        });
    }

    public function index(): Response
    {
        return Inertia::render('Inventory/DeadStockAutomation', [
            'dashboard' => $this->service->getDashboard(),
        ]);
    }

    public function api(): JsonResponse
    {
        return response()->json($this->service->getDashboard());
    }

    public function triggerScan(): RedirectResponse
    {
        $result = $this->service->scan();

        return back()->with('success', "Scan complete: {$result['total_scanned']} items scanned, {$result['flagged_count']} flagged.");
    }

    public function apiTriggerScan(): JsonResponse
    {
        $result = $this->service->scan();

        return response()->json($result);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slow_days' => ['required', 'integer', 'min:1', 'max:365'],
            'non_moving_days' => ['required', 'integer', 'min:1', 'max:730'],
            'dead_days' => ['required', 'integer', 'min:1', 'max:1095'],
            'auto_write_off' => ['boolean'],
            'notify_emails' => ['nullable', 'string', 'max:500'],
            'notify_email_enabled' => ['boolean'],
            'notify_in_app_enabled' => ['boolean'],
            'min_value_threshold' => ['nullable', 'numeric', 'min:0'],
            'scan_frequency' => ['nullable', 'string', 'in:daily,weekly,monthly'],
        ]);

        $this->service->updateSettings($data);

        return back()->with('success', 'Dead stock automation settings updated.');
    }

    public function apiUpdateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slow_days' => ['required', 'integer', 'min:1', 'max:365'],
            'non_moving_days' => ['required', 'integer', 'min:1', 'max:730'],
            'dead_days' => ['required', 'integer', 'min:1', 'max:1095'],
            'auto_write_off' => ['boolean'],
            'notify_emails' => ['nullable', 'string', 'max:500'],
            'notify_email_enabled' => ['boolean'],
            'notify_in_app_enabled' => ['boolean'],
            'min_value_threshold' => ['nullable', 'numeric', 'min:0'],
            'scan_frequency' => ['nullable', 'string', 'in:daily,weekly,monthly'],
        ]);

        $this->service->updateSettings($data);

        return response()->json(['ok' => true, 'settings' => $this->service->getSettings()]);
    }

    public function exportCsv(): StreamedResponse
    {
        $csv = $this->service->exportCsv();
        $filename = 'dead_stock_scan_'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
