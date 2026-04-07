<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();
        $type = $request->input('type', 'sales');

        $data = match ($type) {
            'sales'     => $this->reports->salesReport($from, $to),
            'agents'    => $this->reports->agentReport($from, $to),
            'couriers'  => $this->reports->courierReport($from, $to),
            'products'  => $this->reports->productReport($from, $to),
            'customers' => $this->reports->customerReport($from, $to),
            default     => $this->reports->salesReport($from, $to),
        };

        return Inertia::render('Reports/Index', [
            'report'  => $data,
            'type'    => $type,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'type' => $type],
        ]);
    }

    /**
     * Download report as CSV.
     */
    public function download(Request $request): StreamedResponse
    {
        $from = $request->filled('from') ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();
        $type = $request->input('type', 'sales');

        $data = match ($type) {
            'sales'     => $this->reports->salesReport($from, $to),
            'agents'    => $this->reports->agentReport($from, $to),
            'couriers'  => $this->reports->courierReport($from, $to),
            'products'  => $this->reports->productReport($from, $to),
            'customers' => $this->reports->customerReport($from, $to),
            default     => $this->reports->salesReport($from, $to),
        };

        $filename = "{$type}_report_{$from->format('Ymd')}_{$to->format('Ymd')}.csv";
        $rows = $data['rows'] ?? [];

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            if (!empty($rows)) {
                // Header row
                fputcsv($handle, array_keys($rows[0]));
                // Data rows
                foreach ($rows as $row) {
                    fputcsv($handle, $row);
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
