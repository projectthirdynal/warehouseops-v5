<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Services\DepreciationAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepreciationAutomationController extends Controller
{
    public function __construct(
        private readonly DepreciationAutomationService $service
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
        return Inertia::render('Inventory/DepreciationAutomation', [
            'dashboard' => $this->service->getDashboard(),
        ]);
    }

    public function api(): JsonResponse
    {
        return response()->json($this->service->getDashboard());
    }

    public function triggerPost(): RedirectResponse
    {
        $result = $this->service->postDueEntries();

        return back()->with('success', "Posted {$result['posted']} depreciation entries totaling ".number_format($result['total_amount'], 2));
    }

    public function apiTriggerPost(): JsonResponse
    {
        $result = $this->service->postDueEntries();

        return response()->json($result);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_post' => ['boolean'],
            'posting_day' => ['required', 'integer', 'min:1', 'max:28'],
            'debit_account' => ['nullable', 'string', 'max:100'],
            'credit_account' => ['nullable', 'string', 'max:100'],
            'notify_emails' => ['nullable', 'string', 'max:500'],
            'notify_email_enabled' => ['boolean'],
            'notify_in_app_enabled' => ['boolean'],
        ]);

        $this->service->updateSettings($data);

        return back()->with('success', 'Depreciation automation settings updated.');
    }

    public function apiUpdateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auto_post' => ['boolean'],
            'posting_day' => ['required', 'integer', 'min:1', 'max:28'],
            'debit_account' => ['nullable', 'string', 'max:100'],
            'credit_account' => ['nullable', 'string', 'max:100'],
            'notify_emails' => ['nullable', 'string', 'max:500'],
            'notify_email_enabled' => ['boolean'],
            'notify_in_app_enabled' => ['boolean'],
        ]);

        $this->service->updateSettings($data);

        return response()->json(['ok' => true, 'settings' => $this->service->getSettings()]);
    }

    public function exportCsv(): StreamedResponse
    {
        $csv = $this->service->exportCsv();
        $filename = 'depreciation_journal_'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
