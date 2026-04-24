<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Waybill\Models\UnknownWaybillScan;
use App\Models\Waybill;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UnknownWaybillController extends Controller
{
    public function index(Request $request)
    {
        $unknowns = UnknownWaybillScan::with(['scannedBy', 'resolvedBy', 'resolvedToWaybill'])
            ->when($request->status, fn ($q, $v) => $q->where('resolution_status', $v))
            ->when($request->search, fn ($q, $v) => $q->where('waybill_no', 'ILIKE', "%{$v}%"))
            ->latest('scanned_at')
            ->paginate(30)
            ->withQueryString();

        $stats = [
            'pending'  => UnknownWaybillScan::where('resolution_status', 'PENDING')->count(),
            'resolved' => UnknownWaybillScan::where('resolution_status', 'RESOLVED')->count(),
            'dismissed' => UnknownWaybillScan::where('resolution_status', 'DISMISSED')->count(),
        ];

        // Fuzzy suggestions for pending items
        $pendingNumbers = UnknownWaybillScan::pending()
            ->select('waybill_no')
            ->distinct()
            ->pluck('waybill_no');

        return Inertia::render('Waybills/Unknown', [
            'unknowns' => $unknowns,
            'stats'    => $stats,
            'filters'  => $request->only(['status', 'search']),
        ]);
    }

    public function match(Request $request, UnknownWaybillScan $unknown)
    {
        $data = $request->validate([
            'waybill_id' => 'required|exists:waybills,id',
            'notes'      => 'nullable|string|max:1000',
        ]);

        $unknown->update([
            'resolution_status'      => 'RESOLVED',
            'resolved_to_waybill_id' => $data['waybill_id'],
            'notes'                  => $data['notes'],
            'resolved_by'            => $request->user()->id,
            'resolved_at'            => now(),
        ]);

        return back()->with('success', 'Unknown waybill matched to existing record.');
    }

    public function dismiss(Request $request, UnknownWaybillScan $unknown)
    {
        $data = $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $unknown->update([
            'resolution_status' => 'DISMISSED',
            'notes'             => $data['notes'],
            'resolved_by'       => $request->user()->id,
            'resolved_at'       => now(),
        ]);

        return back()->with('success', 'Unknown waybill dismissed.');
    }

    public function suggest(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim($request->query('q', ''));

        if (strlen($q) < 3) {
            return response()->json(['waybills' => []]);
        }

        $waybills = Waybill::where('waybill_number', 'ILIKE', "%{$q}%")
            ->select(['id', 'waybill_number', 'receiver_name', 'city', 'status'])
            ->limit(10)
            ->get();

        return response()->json(['waybills' => $waybills]);
    }
}
