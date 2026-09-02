<?php

namespace App\Http\Controllers;

use App\Domain\Waybill\Models\GoogleSheetConfig;
use App\Jobs\ProcessWaybillImport;
use App\Jobs\RetryFailedRowsJob;
use App\Models\Upload;
use App\Models\Waybill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

class WaybillImportController extends Controller
{
    public function index()
    {
        $uploads = Upload::where('type', 'waybill')
            ->with('uploadedBy')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // Heavy COUNT(*) over 500k+ waybills was running on every page load — cache for 60s.
        // Live counters (pending_uploads, recent_errors) stay uncached for accuracy.
        $stats = [
            'total_uploads' => Cache::remember(
                'waybill_import:total_uploads', 60,
                fn () => Upload::where('type', 'waybill')->count()
            ),
            'total_imported' => Cache::remember(
                'waybill_import:total_imported', 60,
                fn () => (int) Upload::where('type', 'waybill')->sum('success_rows')
            ),
            'pending_uploads' => Upload::where('type', 'waybill')
                ->whereIn('status', [
                    Upload::STATUS_PENDING,
                    Upload::STATUS_QUEUED,
                    Upload::STATUS_VALIDATING,
                    Upload::STATUS_READY_TO_PROCESS,
                    Upload::STATUS_PROCESSING,
                ])
                ->count(),
            'recent_errors' => Upload::where('type', 'waybill')
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
        ];

        return Inertia::render('Waybills/Import', [
            'uploads' => $uploads,
            'stats' => $stats,
            'sheet_configs' => GoogleSheetConfig::orderBy('courier')
                ->orderByRaw("ARRAY_POSITION(ARRAY['January','February','March','April','May','June','July','August','September','October','November','December'], month)")
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'courier' => $c->courier,
                    'month' => $c->month,
                    'data_year' => $c->data_year,
                    'sheet_url' => $c->sheet_url,
                    'enabled' => $c->enabled,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:102400',
            'courier' => 'required|string|in:jnt,flash,spx',
        ]);

        $file = $request->file('file');
        $courier = $request->input('courier');

        $filename = time().'_'.$file->getClientOriginalName();
        $path = $file->storeAs('uploads/waybills', $filename, 'local');

        $fileHash = hash_file('sha256', storage_path('app/'.$path));

        $existing = Upload::where('type', 'waybill')
            ->where('file_hash', $fileHash)
            ->whereIn('status', [
                Upload::STATUS_PENDING,
                Upload::STATUS_QUEUED,
                Upload::STATUS_VALIDATING,
                Upload::STATUS_READY_TO_PROCESS,
                Upload::STATUS_PROCESSING,
                Upload::STATUS_COMPLETED,
                Upload::STATUS_COMPLETED_WITH_ERRORS,
            ])
            ->first();

        if ($existing) {
            Storage::disk('local')->delete('uploads/waybills/'.$filename);

            return response()->json([
                'error' => 'This file has already been uploaded.',
                'existing_upload_id' => $existing->id,
                'existing_filename' => $existing->original_filename,
                'existing_status' => $existing->status,
            ], 422);
        }

        $upload = Upload::create([
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'type' => 'waybill',
            'courier' => $courier,
            'import_type' => 'auto_sync',
            'file_hash' => $fileHash,
            'status' => Upload::STATUS_QUEUED,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['upload_id' => $upload->id]);
    }

    /**
     * Sync waybills from a public Google Sheet URL.
     * Downloads the sheet as CSV via the GViz export endpoint, saves to a temp
     * file, then dispatches the existing ProcessWaybillImport pipeline.
     * Re-syncing the same sheet will upsert — unchanged rows are skipped, new
     * rows are inserted, and status-changed rows are updated with tracking history.
     */
    public function syncSheet(Request $request)
    {
        $validated = $request->validate([
            'courier' => 'required|string|in:jnt,flash,spx',
            'sheet_url' => 'required|string|max:2000',
            'month' => 'nullable|string|max:50',
            'data_year' => 'nullable|integer',
        ]);

        $courier = $validated['courier'];
        $sheetUrl = $validated['sheet_url'];

        // Extract spreadsheet ID from the URL
        $spreadsheetId = $this->extractSpreadsheetId($sheetUrl);
        if (! $spreadsheetId) {
            return response()->json(['error' => 'Invalid Google Sheet URL. Could not extract spreadsheet ID.'], 422);
        }

        // Extract gid from URL if present (specific tab)
        $gid = $this->extractGid($sheetUrl);

        // Build the CSV export URL
        $csvUrl = $this->buildCsvExportUrl($spreadsheetId, $gid);

        // Download the CSV with a hard size cap and content-type guard
        $maxBytes = 50 * 1024 * 1024;
        $tempDir = storage_path('app/uploads/waybills');
        $filename = 'gsheet_'.uniqid('', true).'_'.$courier.'.csv';
        $tempPath = $tempDir.'/'.$filename;
        $handle = null;
        $stream = null;
        $written = 0;
        $success = false;
        $error = null;

        try {
            $response = Http::timeout(120)->withOptions(['stream' => true])->get($csvUrl);

            if (! $response->ok()) {
                return response()->json(['error' => 'Failed to download Google Sheet. HTTP '.$response->status().'. Ensure the sheet is shared as "Anyone with the link — Viewer".'], 422);
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            if (! str_contains($contentType, 'csv') && ! str_contains($contentType, 'text/plain') && ! str_contains($contentType, 'application/octet-stream')) {
                return response()->json(['error' => 'The URL did not return a CSV file. Make sure the sheet is shared as "Anyone with the link — Viewer".'], 422);
            }

            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $stream = $response->toPsrResponse()->getBody();
            $handle = fopen($tempPath, 'w');
            if (! is_resource($handle)) {
                throw new \RuntimeException('Could not open a temporary file for writing.');
            }

            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new \RuntimeException('The Google Sheet CSV is too large (max '.($maxBytes / 1024 / 1024).' MB).');
                }

                if (fwrite($handle, $chunk) === false) {
                    throw new \RuntimeException('Failed to write the downloaded CSV chunk to disk.');
                }
            }

            if ($written === 0) {
                throw new \RuntimeException('The Google Sheet appears to be empty.');
            }

            $success = true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            if ($stream !== null) {
                $stream->close();
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (! $success && $tempPath !== null && file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        if (! $success) {
            return response()->json(['error' => 'Failed to download Google Sheet: '.$error], 422);
        }

        $fileHash = hash_file('sha256', $tempPath);

        // Save/update the sheet config for future re-syncs
        $month = $validated['month'] ?? now()->format('F');
        $dataYear = $validated['data_year'] ?? now()->year;
        GoogleSheetConfig::updateOrCreate(
            [
                'courier' => $courier,
                'month' => $month,
                'data_year' => $dataYear,
            ],
            [
                'sheet_url' => $sheetUrl,
                'enabled' => true,
                'updated_by' => $request->user()->id,
            ]
        );

        // Create upload record
        $label = strtoupper($courier).' '.$month.' '.$dataYear;
        $upload = Upload::create([
            'filename' => $filename,
            'original_filename' => 'Google Sheet: '.$label,
            'type' => 'waybill',
            'courier' => $courier,
            'import_type' => 'google_sync',
            'file_hash' => $fileHash,
            'status' => Upload::STATUS_PROCESSING,
            'started_at' => now(),
            'uploaded_by' => $request->user()->id,
        ]);

        // Dispatch the import job directly (skip validation step — sheets are trusted)
        ProcessWaybillImport::dispatch(
            $upload->id,
            $courier,
            $tempPath,
            $request->user()->id,
        );

        return response()->json([
            'upload_id' => $upload->id,
            'message' => 'Sync started for '.$label.'. Downloading and processing in the background.',
        ]);
    }

    /**
     * Save sheet URL configuration for a courier (without triggering sync).
     */
    public function saveSheetConfig(Request $request)
    {
        $validated = $request->validate([
            'courier' => 'required|string|in:jnt,flash,spx',
            'sheet_url' => 'nullable|string|max:2000',
            'month' => 'nullable|string|max:50',
            'data_year' => 'nullable|integer',
            'enabled' => 'boolean',
        ]);

        $month = $validated['month'] ?? now()->format('F');
        $dataYear = $validated['data_year'] ?? now()->year;

        GoogleSheetConfig::updateOrCreate(
            [
                'courier' => $validated['courier'],
                'month' => $month,
                'data_year' => $dataYear,
            ],
            [
                'sheet_url' => $validated['sheet_url'] ?? null,
                'enabled' => $validated['enabled'] ?? true,
                'updated_by' => $request->user()->id,
            ]
        );

        return response()->json(['success' => true]);
    }

    /**
     * Extract the spreadsheet ID from a Google Sheets URL.
     */
    private function extractSpreadsheetId(string $url): ?string
    {
        if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract the gid (tab ID) from a Google Sheets URL.
     */
    private function extractGid(string $url): ?string
    {
        if (preg_match('/[?#&]gid=(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Build the CSV export URL for a Google Sheet.
     * Uses the export endpoint which works for "Anyone with the link" shared sheets.
     */
    private function buildCsvExportUrl(string $spreadsheetId, ?string $gid): string
    {
        $params = ['format' => 'csv'];
        if ($gid) {
            $params['gid'] = $gid;
        }

        return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?".http_build_query($params);
    }

    public function validateUpload(Request $request, Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        if ($upload->status !== Upload::STATUS_QUEUED) {
            return response()->json(['error' => 'Upload is not in queued state.'], 422);
        }

        $upload->update(['status' => Upload::STATUS_VALIDATING]);

        $filePath = Storage::disk('local')->path('uploads/waybills/'.$upload->filename);

        if (! file_exists($filePath)) {
            $upload->markAsValidationFailed(['message' => 'Uploaded file not found.']);

            return response()->json(['valid' => false, 'errors' => ['File not found on server.']], 422);
        }

        $requiredHeaders = match ($upload->courier) {
            'jnt' => [
                'waybill_number' => ['Waybill Number', 'waybill_number'],
                'status' => ['Order Status', 'order_status'],
            ],
            'spx' => [
                'waybill_number' => ['Tracking Number', 'tracking_number', 'Tracking No', 'Tracking No.', 'Waybill Number', 'waybill_number'],
                'status' => ['Status', 'status', 'Order Status', 'order_status', 'Parcel Status', 'parcel_status'],
            ],
            'flash' => [
                'waybill_number' => ['Tracking Number', 'tracking_number', 'Tracking No', 'Tracking No.', 'Waybill Number', 'waybill_number'],
                'status' => ['Status', 'status', 'Order Status', 'order_status'],
            ],
            default => [
                'waybill_number' => ['Tracking Number', 'Waybill Number'],
                'status' => ['Status', 'Order Status'],
            ],
        };

        $sampleRows = [];
        $detectedHeaders = [];
        $rowCount = 0;
        $duplicates = [];
        $seenWaybills = [];
        $missingHeaders = [];

        // Sentinel thrown inside the FastExcel callback to stop iteration after 200 rows,
        // since rap2hpoutre/fast-excel does not support early termination via return value.
        $done = new \RuntimeException('__validation_sample_done__');

        try {
            (new FastExcel)->import($filePath, function ($row) use (
                &$sampleRows, &$detectedHeaders, &$rowCount,
                &$duplicates, &$seenWaybills, $requiredHeaders, &$missingHeaders, $done
            ) {
                $rowCount++;

                if ($rowCount === 1) {
                    $detectedHeaders = array_keys($row);
                    foreach ($requiredHeaders as $field => $aliases) {
                        $found = false;
                        foreach ($aliases as $alias) {
                            foreach ($detectedHeaders as $header) {
                                if (strtolower(trim($header)) === strtolower(trim($alias))) {
                                    $found = true;
                                    break 2;
                                }
                            }
                        }
                        if (! $found) {
                            $missingHeaders[] = $aliases[0];
                        }
                    }
                }

                if (count($sampleRows) < 20) {
                    $sampleRows[] = $row;
                }

                $waybill = null;
                foreach ($requiredHeaders['waybill_number'] as $alias) {
                    if (isset($row[$alias]) && $row[$alias] !== '') {
                        $waybill = $row[$alias];
                        break;
                    }
                }

                if ($waybill !== null && $waybill !== '') {
                    $waybill = trim((string) $waybill);
                    if (isset($seenWaybills[$waybill])) {
                        $duplicates[] = $waybill;
                    }
                    $seenWaybills[$waybill] = true;
                }

                if ($rowCount >= 200) {
                    throw $done;
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__validation_sample_done__') {
                $upload->markAsValidationFailed(['message' => 'File could not be read: '.$e->getMessage()]);

                return response()->json(['valid' => false, 'errors' => [$e->getMessage()]], 422);
            }
        } catch (\Throwable $e) {
            $upload->markAsValidationFailed(['message' => 'File could not be read: '.$e->getMessage()]);

            return response()->json(['valid' => false, 'errors' => [$e->getMessage()]], 422);
        }

        if (! empty($missingHeaders)) {
            $upload->markAsValidationFailed(['missing_headers' => $missingHeaders]);

            return response()->json([
                'valid' => false,
                'missing_headers' => $missingHeaders,
                'errors' => array_map(fn ($h) => "Required column \"{$h}\" not found.", $missingHeaders),
            ], 422);
        }

        $preview = [
            'detected_columns' => $detectedHeaders,
            'sample_rows' => $sampleRows,
            'duplicate_waybills_count' => count(array_unique($duplicates)),
        ];

        $upload->markAsReadyToProcess();
        $upload->update(['metadata' => ['preview' => $preview]]);

        return response()->json([
            'valid' => true,
            'total_rows_detected' => $rowCount >= 200 ? '200+' : $rowCount,
            'detected_columns' => $detectedHeaders,
            'sample_rows' => $sampleRows,
            'duplicate_waybills_count' => count(array_unique($duplicates)),
            'missing_headers' => [],
            'warnings' => count($duplicates) > 0
                ? [count(array_unique($duplicates)).' duplicate waybill numbers detected in first 200 rows.']
                : [],
        ]);
    }

    public function start(Request $request, Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        if ($upload->status !== Upload::STATUS_READY_TO_PROCESS) {
            return response()->json(['error' => 'Upload must be validated before starting.'], 422);
        }

        $upload->update([
            'status' => Upload::STATUS_PROCESSING,
            'started_at' => now(),
            'errors' => null,
        ]);

        ProcessWaybillImport::dispatch(
            $upload->id,
            $upload->courier,
            Storage::disk('local')->path('uploads/waybills/'.$upload->filename),
            $request->user()->id,
        );

        return response()->json(['message' => 'Import started.']);
    }

    public function preview(Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        if (! in_array($upload->status, [
            Upload::STATUS_READY_TO_PROCESS,
            Upload::STATUS_PROCESSING,
            Upload::STATUS_COMPLETED,
            Upload::STATUS_COMPLETED_WITH_ERRORS,
        ])) {
            return response()->json(['error' => 'Preview not available.'], 404);
        }

        return response()->json($upload->errors ?? []);
    }

    public function errorsDownload(Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        $errors = $upload->errors;

        if (empty($errors) || ! is_array($errors) || ! isset($errors[0]['row'])) {
            abort(404, 'No error report available.');
        }

        $filename = 'import_errors_'.$upload->id.'.csv';

        return response()->streamDownload(function () use ($errors) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Row', 'Error']);
            foreach ($errors as $e) {
                fputcsv($out, [$e['row'] ?? '', $e['error'] ?? '']);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function show(Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        $upload->load('uploadedBy');

        $waybills = Waybill::where('upload_id', $upload->id)
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return Inertia::render('Waybills/ImportDetail', [
            'upload' => $upload,
            'waybills' => $waybills,
        ]);
    }

    public function template(Request $request)
    {
        $courier = $request->query('courier', 'jnt');

        $headers = $courier === 'jnt' ? [
            'Waybill Number',
            'Order Status',
            'Receiver',
            'Receiver Cellphone',
            'Province',
            'City',
            'Barangay',
            'Address',
            'Item Name',
            'Number of Items',
            'COD',
            'Total Shipping Cost',
            'Remarks',
        ] : [
            'Tracking Number',
            'Status',
            'Consignee Name',
            'Consignee Phone',
            'Province',
            'City',
            'Barangay',
            'Address',
            'Product Name',
            'Quantity',
            'COD Amount',
            'Shipping Fee',
            'Notes',
        ];

        $filename = "waybill_import_template_{$courier}.xlsx";
        $tempDir = storage_path('app/temp');
        $tempPath = $tempDir.'/'.$filename;

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        (new FastExcel(collect([array_fill_keys($headers, '')])
        ))->export($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function status(Upload $upload)
    {
        return response()->json([
            'id' => $upload->id,
            'status' => $upload->status,
            'courier' => $upload->courier,
            'import_type' => $upload->import_type,
            'total_rows' => $upload->total_rows,
            'processed_rows' => $upload->processed_rows,
            'success_rows' => $upload->success_rows,
            'inserted_rows' => $upload->inserted_rows,
            'updated_rows' => $upload->updated_rows,
            'skipped_rows' => $upload->skipped_rows,
            'error_rows' => $upload->error_rows,
            'retry_status' => $upload->retry_status,
            'retry_count' => $upload->retry_count,
        ]);
    }

    public function cancel(Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        $cancellable = [
            Upload::STATUS_QUEUED,
            Upload::STATUS_VALIDATING,
            Upload::STATUS_READY_TO_PROCESS,
            Upload::STATUS_PROCESSING,
            Upload::STATUS_PENDING,
        ];

        if (! in_array($upload->status, $cancellable)) {
            return back()->with('error', 'This upload cannot be cancelled.');
        }

        $upload->markAsCancelled();

        return back()->with('success', 'Upload cancelled. Already-imported waybills are preserved.');
    }

    public function retry(Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        if ($upload->status !== 'failed') {
            return back()->with('error', 'Only failed uploads can be retried.');
        }

        $path = 'uploads/waybills/'.$upload->filename;

        if (! Storage::disk('local')->exists($path)) {
            return back()->with('error', 'Original file not found. Please re-upload.');
        }

        $upload->update([
            'status' => Upload::STATUS_PROCESSING,
            'started_at' => now(),
            'completed_at' => null,
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_rows' => 0,
            'inserted_rows' => 0,
            'updated_rows' => 0,
            'skipped_rows' => 0,
            'error_rows' => 0,
            'errors' => null,
        ]);

        ProcessWaybillImport::dispatch(
            $upload->id,
            $upload->courier,
            Storage::disk('local')->path($path),
            $upload->uploaded_by,
        );

        return back()->with('success', 'Retry queued. Processing in the background — check the status below.');
    }

    public function retryFailedRows(Request $request, Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        if (! in_array($upload->status, [Upload::STATUS_COMPLETED_WITH_ERRORS, Upload::STATUS_FAILED])) {
            return response()->json(['error' => 'Only completed-with-errors or failed uploads can retry failed rows.'], 422);
        }

        if ($upload->retry_status === 'processing') {
            return response()->json(['error' => 'A retry is already in progress.'], 422);
        }

        $errors = $upload->errors ?? [];
        $failedRowCount = count(array_filter($errors, fn ($e) => is_numeric($e['row'] ?? null)));

        if ($failedRowCount === 0) {
            return response()->json(['error' => 'No retryable row errors found. The errors may be batch-level failures.'], 422);
        }

        $path = 'uploads/waybills/'.$upload->filename;
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'Original file not found. Please re-upload.'], 422);
        }

        $upload->update([
            'retry_status' => 'queued',
        ]);

        RetryFailedRowsJob::dispatch($upload->id, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => "Retry queued for {$failedRowCount} failed rows.",
        ]);
    }

    public function errorDetails(Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        $errors = $upload->errors ?? [];

        if (empty($errors)) {
            return response()->json(['errors' => [], 'retry_status' => $upload->retry_status]);
        }

        $rowErrors = array_filter($errors, fn ($e) => is_numeric($e['row'] ?? null));
        $batchErrors = array_filter($errors, fn ($e) => ! is_numeric($e['row'] ?? null));

        return response()->json([
            'errors' => array_values($errors),
            'row_errors_count' => count($rowErrors),
            'batch_errors_count' => count($batchErrors),
            'retry_status' => $upload->retry_status,
            'retry_count' => $upload->retry_count,
            'can_retry' => in_array($upload->status, [Upload::STATUS_COMPLETED_WITH_ERRORS, Upload::STATUS_FAILED])
                && $upload->retry_status !== 'processing'
                && count($rowErrors) > 0,
        ]);
    }
}
