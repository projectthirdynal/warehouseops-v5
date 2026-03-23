<?php

namespace App\Http\Controllers;

use App\Imports\JntWaybillFastImport;
use App\Jobs\GenerateLeadsFromUpload;
use App\Models\Upload;
use App\Models\Waybill;
use Illuminate\Http\Request;
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

        $stats = [
            'total_uploads' => Upload::where('type', 'waybill')->count(),
            'total_imported' => Waybill::whereNotNull('upload_id')->count(),
            'pending_uploads' => Upload::where('type', 'waybill')
                ->whereIn('status', ['pending', 'processing'])
                ->count(),
            'recent_errors' => Upload::where('type', 'waybill')
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
        ];

        return Inertia::render('Waybills/Import', [
            'uploads' => $uploads,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:102400', // 100MB max
            'courier' => 'required|string|in:jnt,flash',
        ]);

        $file = $request->file('file');
        $courier = $request->input('courier');

        // Store the file
        $filename = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/waybills', $filename, 'local');

        // Create upload record
        $upload = Upload::create([
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'type' => 'waybill',
            'status' => 'processing',
            'uploaded_by' => $request->user()->id,
        ]);

        try {
            // Count total rows using fast streaming (no memory issues)
            $rowCount = 0;
            (new FastExcel)->import(storage_path('app/' . $path), function ($row) use (&$rowCount) {
                $rowCount++;
            });
            $upload->update(['total_rows' => $rowCount]);

            // Import based on courier using fast streaming import
            if ($courier === 'jnt') {
                $import = new JntWaybillFastImport($upload, $request->user()->id);
                $import->import(storage_path('app/' . $path));

                $upload->update([
                    'status' => 'completed',
                    'errors' => $import->getErrors(),
                ]);

                // Auto-generate leads from delivered waybills in this batch
                GenerateLeadsFromUpload::dispatch($upload->id);

                return back()->with('success', sprintf(
                    'Import completed! %d waybills imported, %d errors. Leads are being generated in the background.',
                    $import->getSuccessCount(),
                    $import->getErrorCount()
                ));
            }

            // Flash courier (similar implementation)
            // TODO: Implement FlashWaybillFastImport

            return back()->with('success', 'Import completed successfully.');

        } catch (\Exception $e) {
            $upload->markAsFailed(['message' => $e->getMessage()]);

            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    public function show(Upload $upload)
    {
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

        // Define template headers based on courier
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

        // Generate template using PhpSpreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Write headers
        foreach ($headers as $col => $header) {
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
        }

        // Style headers
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0'],
            ],
        ];
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray($headerStyle);

        // Auto-size columns
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Create response
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        $filename = "waybill_import_template_{$courier}.xlsx";
        $tempPath = storage_path('app/temp/' . $filename);

        if (!is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function retry(Upload $upload)
    {
        if ($upload->status !== 'failed') {
            return back()->with('error', 'Only failed uploads can be retried.');
        }

        $path = 'uploads/waybills/' . $upload->filename;

        if (!Storage::disk('local')->exists($path)) {
            return back()->with('error', 'Original file not found. Please re-upload.');
        }

        // Reset upload stats
        $upload->update([
            'status' => 'processing',
            'processed_rows' => 0,
            'success_rows' => 0,
            'error_rows' => 0,
            'errors' => null,
        ]);

        try {
            $import = new JntWaybillFastImport($upload, $upload->uploaded_by);
            $import->import(storage_path('app/' . $path));

            $upload->update([
                'status' => 'completed',
                'errors' => $import->getErrors(),
            ]);

            GenerateLeadsFromUpload::dispatch($upload->id);

            return back()->with('success', sprintf(
                'Retry completed! %d waybills imported, %d errors. Leads are being generated in the background.',
                $import->getSuccessCount(),
                $import->getErrorCount()
            ));

        } catch (\Exception $e) {
            $upload->markAsFailed(['message' => $e->getMessage()]);

            return back()->with('error', 'Retry failed: ' . $e->getMessage());
        }
    }
}
