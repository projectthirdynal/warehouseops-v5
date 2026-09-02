<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Modules\Inventory\Services\AdjustmentBulkImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdjustmentBulkImportController extends Controller
{
    public function __construct(
        private readonly AdjustmentBulkImportService $service
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()?->role, [
                'superadmin', 'admin', 'supervisor', 'warehouse', 'accounting', 'finance',
            ])) {
                abort(403, 'Access denied');
            }

            return $next($request);
        });
    }

    public function index(): Response
    {
        return Inertia::render('Inventory/AdjustmentBulkImport', [
            'reason_codes' => AdjustmentBulkImportService::VALID_REASON_CODES,
            'item_types' => AdjustmentBulkImportService::VALID_ITEM_TYPES,
            'required_headers' => AdjustmentBulkImportService::REQUIRED_HEADERS,
            'optional_headers' => AdjustmentBulkImportService::OPTIONAL_HEADERS,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();

        $parsed = $this->service->parseCsv($path);

        if (! empty($parsed['errors'])) {
            return response()->json([
                'errors' => $parsed['errors'],
                'headers' => $parsed['headers'],
                'rows' => [],
                'valid_rows' => [],
                'error_rows' => [],
                'warnings' => [],
                'summary' => [
                    'total_rows' => 0,
                    'valid_count' => 0,
                    'error_count' => count($parsed['errors']),
                    'warning_count' => 0,
                ],
            ], 422);
        }

        $validation = $this->service->validateRows($parsed['rows']);

        return response()->json([
            'headers' => $parsed['headers'],
            'valid_rows' => $validation['valid_rows'],
            'error_rows' => $validation['error_rows'],
            'warnings' => $validation['warnings'],
            'summary' => $validation['summary'],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.item_type' => ['required', 'string', 'in:product,supply'],
            'rows.*.sku' => ['required', 'string'],
            'rows.*.variant_sku' => ['nullable', 'string'],
            'rows.*.variant_id' => ['nullable', 'integer'],
            'rows.*.warehouse_code' => ['required', 'string'],
            'rows.*.quantity_after' => ['required', 'integer', 'min:0'],
            'rows.*.reason_code' => ['required', 'string'],
            'rows.*.reason_notes' => ['nullable', 'string'],
            'rows.*.row_number' => ['nullable', 'integer'],
        ]);

        $result = $this->service->import($request->input('rows'), $request->user()->id);

        return response()->json($result);
    }

    public function template(): StreamedResponse
    {
        $csv = $this->service->generateTemplate();

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, 'adjustment-bulk-import-template.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }
}
