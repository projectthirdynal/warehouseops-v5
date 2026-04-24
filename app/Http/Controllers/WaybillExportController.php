<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Waybill\Models\Claim;
use App\Exports\BeyondSlaExport;
use App\Exports\ClaimsExport;
use App\Domain\Waybill\Models\Waybill;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class WaybillExportController extends Controller
{
    public function claims(Request $request)
    {
        $filters  = $request->only(['status', 'type', 'from', 'to', 'search']);
        $format   = $request->query('format', 'xlsx');
        $filename = 'claims_' . now()->format('Ymd_His');

        $this->logExport($request, 'claims', $filters);

        if ($format === 'pdf') {
            return $this->claimsPdf($filters, $filename);
        }

        $export = new ClaimsExport($filters, $request->user()->name);

        return $format === 'csv'
            ? Excel::download($export, "{$filename}.csv", \Maatwebsite\Excel\Excel::CSV, ['Content-Type' => 'text/csv; charset=UTF-8'])
            : Excel::download($export, "{$filename}.xlsx");
    }

    public function beyondSla(Request $request)
    {
        $filters  = $request->only(['from', 'to', 'search']);
        $format   = $request->query('format', 'xlsx');
        $filename = 'beyond_sla_' . now()->format('Ymd_His');

        $this->logExport($request, 'beyond_sla', $filters);

        if ($format === 'pdf') {
            return $this->beyondSlaPdf($filters, $filename);
        }

        $export = new BeyondSlaExport($filters);

        return $format === 'csv'
            ? Excel::download($export, "{$filename}.csv", \Maatwebsite\Excel\Excel::CSV, ['Content-Type' => 'text/csv; charset=UTF-8'])
            : Excel::download($export, "{$filename}.xlsx");
    }

    private function claimsPdf(array $filters, string $filename): \Illuminate\Http\Response
    {
        $claims = Claim::with(['waybill', 'filedBy'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('filed_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('filed_at', '<=', $v . ' 23:59:59'))
            ->latest('filed_at')
            ->get();

        $pdf = app('dompdf.wrapper');
        $pdf->loadView('exports.claims-pdf', [
            'claims'    => $claims,
            'filters'   => $filters,
            'generated' => now()->setTimezone('Asia/Manila')->format('F j, Y H:i'),
        ]);
        $pdf->setPaper('a4', 'landscape');

        return $pdf->download("{$filename}.pdf");
    }

    private function beyondSlaPdf(array $filters, string $filename): \Illuminate\Http\Response
    {
        $slaCutoff = now()->setTimezone('Asia/Manila')->startOfDay()->subDay()->utc();

        $waybills = Waybill::where('status', 'RETURNED')
            ->where('returned_at', '<', $slaCutoff)
            ->whereDoesntHave('returnReceipt')
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('returned_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('returned_at', '<=', $v . ' 23:59:59'))
            ->latest('returned_at')
            ->get();

        $pdf = app('dompdf.wrapper');
        $pdf->loadView('exports.beyond-sla-pdf', [
            'waybills'  => $waybills,
            'filters'   => $filters,
            'generated' => now()->setTimezone('Asia/Manila')->format('F j, Y H:i'),
        ]);
        $pdf->setPaper('a4', 'landscape');

        return $pdf->download("{$filename}.pdf");
    }

    private function logExport(Request $request, string $type, array $filters): void
    {
        \Illuminate\Support\Facades\Log::channel('daily')->info('Export', [
            'type'       => $type,
            'filters'    => $filters,
            'user_id'    => $request->user()->id,
            'user_name'  => $request->user()->name,
            'ip'         => $request->ip(),
            'timestamp'  => now()->toIso8601String(),
        ]);
    }
}
