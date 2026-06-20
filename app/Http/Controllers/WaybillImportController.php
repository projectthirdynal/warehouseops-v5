<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWaybillImport;
use App\Models\Upload;
use App\Models\Waybill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            'stats'   => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file'        => 'required|file|mimes:xlsx,xls,csv|max:102400',
            'courier'     => 'required|string|in:jnt,flash',
        ]);

        $file       = $request->file('file');
        $courier    = $request->input('courier');

        $filename = time() . '_' . $file->getClientOriginalName();
        $path     = $file->storeAs('uploads/waybills', $filename, 'local');

        $fileHash = hash_file('sha256', storage_path('app/' . $path));

        $upload = Upload::create([
            'filename'          => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'type'              => 'waybill',
            'courier'           => $courier,
            'import_type'       => 'auto_sync',
            'file_hash'         => $fileHash,
            'status'            => Upload::STATUS_QUEUED,
            'uploaded_by'       => $request->user()->id,
        ]);

        return response()->json(['upload_id' => $upload->id]);
    }

    public function validateUpload(Request $request, Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        if ($upload->status !== Upload::STATUS_QUEUED) {
            return response()->json(['error' => 'Upload is not in queued state.'], 422);
        }

        $upload->update(['status' => Upload::STATUS_VALIDATING]);

        $filePath = \Illuminate\Support\Facades\Storage::disk('local')->path('uploads/waybills/' . $upload->filename);

        if (!file_exists($filePath)) {
            $upload->markAsValidationFailed(['message' => 'Uploaded file not found.']);
            return response()->json(['valid' => false, 'errors' => ['File not found on server.']], 422);
        }

        $requiredHeaders = $upload->courier === 'jnt'
            ? ['Waybill Number', 'Order Status']
            : ['Tracking No.', 'Status'];

        $sampleRows      = [];
        $detectedHeaders = [];
        $rowCount        = 0;
        $duplicates      = [];
        $seenWaybills    = [];
        $missingHeaders  = [];

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
                    foreach ($requiredHeaders as $required) {
                        $found = false;
                        foreach ($detectedHeaders as $header) {
                            if (strtolower(trim($header)) === strtolower(trim($required))) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $missingHeaders[] = $required;
                        }
                    }
                }

                if (count($sampleRows) < 20) {
                    $sampleRows[] = $row;
                }

                $waybill = $row['Waybill Number'] ?? $row['Tracking No.'] ?? null;
                if ($waybill) {
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
                $upload->markAsValidationFailed(['message' => 'File could not be read: ' . $e->getMessage()]);
                return response()->json(['valid' => false, 'errors' => [$e->getMessage()]], 422);
            }
        } catch (\Throwable $e) {
            $upload->markAsValidationFailed(['message' => 'File could not be read: ' . $e->getMessage()]);
            return response()->json(['valid' => false, 'errors' => [$e->getMessage()]], 422);
        }

        if (!empty($missingHeaders)) {
            $upload->markAsValidationFailed(['missing_headers' => $missingHeaders]);
            return response()->json([
                'valid'           => false,
                'missing_headers' => $missingHeaders,
                'errors'          => array_map(fn ($h) => "Required column \"{$h}\" not found.", $missingHeaders),
            ], 422);
        }

        $preview = [
            'detected_columns'         => $detectedHeaders,
            'sample_rows'              => $sampleRows,
            'duplicate_waybills_count' => count(array_unique($duplicates)),
        ];

        // errors column reused to store preview data at ready_to_process state; cleared when job starts
        $upload->update([
            'status' => Upload::STATUS_READY_TO_PROCESS,
            'errors' => $preview,
        ]);

        return response()->json([
            'valid'                    => true,
            'total_rows_detected'      => $rowCount >= 200 ? '200+' : $rowCount,
            'detected_columns'         => $detectedHeaders,
            'sample_rows'              => $sampleRows,
            'duplicate_waybills_count' => count(array_unique($duplicates)),
            'missing_headers'          => [],
            'warnings'                 => count($duplicates) > 0
                ? [count(array_unique($duplicates)) . ' duplicate waybill numbers detected in first 200 rows.']
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
            'status'     => Upload::STATUS_PROCESSING,
            'started_at' => now(),
            'errors'     => null,
        ]);

        ProcessWaybillImport::dispatch(
            $upload->id,
            $upload->courier,
            Storage::disk('local')->path('uploads/waybills/' . $upload->filename),
            $request->user()->id,
        );

        return response()->json(['message' => 'Import started.']);
    }

    public function preview(Upload $upload)
    {
        abort_unless($upload->type === 'waybill', 404);

        if (!in_array($upload->status, [
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

        if (empty($errors) || !is_array($errors) || !isset($errors[0]['row'])) {
            abort(404, 'No error report available.');
        }

        $filename = 'import_errors_' . $upload->id . '.csv';

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
            'upload'   => $upload,
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
        $tempDir  = storage_path('app/temp');
        $tempPath = $tempDir . '/' . $filename;

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        (new FastExcel(collect([array_fill_keys($headers, '')])
        ))->export($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function status(Upload $upload)
    {
        return response()->json([
            'id'             => $upload->id,
            'status'         => $upload->status,
            'courier'        => $upload->courier,
            'import_type'    => $upload->import_type,
            'total_rows'     => $upload->total_rows,
            'processed_rows' => $upload->processed_rows,
            'success_rows'   => $upload->success_rows,
            'inserted_rows'  => $upload->inserted_rows,
            'updated_rows'   => $upload->updated_rows,
            'skipped_rows'   => $upload->skipped_rows,
            'error_rows'     => $upload->error_rows,
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

        if (!in_array($upload->status, $cancellable)) {
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

        $path = 'uploads/waybills/' . $upload->filename;

        if (!Storage::disk('local')->exists($path)) {
            return back()->with('error', 'Original file not found. Please re-upload.');
        }

        $upload->update([
            'status'         => Upload::STATUS_PROCESSING,
            'started_at'     => now(),
            'completed_at'   => null,
            'total_rows'     => 0,
            'processed_rows' => 0,
            'success_rows'   => 0,
            'inserted_rows'  => 0,
            'updated_rows'   => 0,
            'skipped_rows'   => 0,
            'error_rows'     => 0,
            'errors'         => null,
        ]);

        ProcessWaybillImport::dispatch(
            $upload->id,
            $upload->courier,
            Storage::disk('local')->path($path),
            $upload->uploaded_by,
        );

        return back()->with('success', 'Retry queued. Processing in the background — check the status below.');
    }
}
