<?php

namespace App\Http\Controllers;

use App\Imports\FlashWaybillFastImport;
use App\Imports\JntWaybillFastImport;
use App\Jobs\GenerateLeadsFromUpload;
use App\Jobs\ProcessWaybillImport;
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

        // Create upload record as pending — processing happens in the queue
        $upload = Upload::create([
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'type' => 'waybill',
            'status' => 'processing',
            'uploaded_by' => $request->user()->id,
        ]);

        // Dispatch background job — returns immediately, no Cloudflare timeout
        ProcessWaybillImport::dispatch(
            $upload->id,
            $courier,
            storage_path('app/' . $path),
            $request->user()->id,
        );

        return back()->with('success', 'File uploaded successfully. Processing in the background — check the status below.');
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

    public function cancel(Upload $upload)
    {
        if (!in_array($upload->status, ['processing', 'pending'])) {
            return back()->with('error', 'Only processing or pending uploads can be cancelled.');
        }

        $upload->update(['status' => 'cancelled']);

        // Delete waybills that were imported as part of this upload
        $deleted = Waybill::where('upload_id', $upload->id)->delete();

        return back()->with('success', "Upload cancelled. {$deleted} imported waybills removed.");
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

        // Detect courier from existing waybills or filename
        $courierProvider = Waybill::where('upload_id', $upload->id)->value('courier_provider');
        $isFlash = $courierProvider === 'FLASH' || str_contains(strtolower($upload->original_filename ?? ''), 'flash');
        $courier = $isFlash ? 'flash' : 'jnt';

        ProcessWaybillImport::dispatch(
            $upload->id,
            $courier,
            storage_path('app/' . $path),
            $upload->uploaded_by,
        );

        return back()->with('success', 'Retry queued. Processing in the background — check the status below.');
    }
}
