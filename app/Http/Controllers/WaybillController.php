<?php

namespace App\Http\Controllers;

use App\Models\Waybill;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WaybillController extends Controller
{
    public function index(Request $request)
    {
        $query = Waybill::query();

        // Apply filters
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('waybill_number', 'ILIKE', "%{$search}%")
                    ->orWhere('receiver_name', 'ILIKE', "%{$search}%")
                    ->orWhere('receiver_phone', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->has('courier') && $request->courier) {
            $query->where('courier_provider', $request->courier);
        }

        // Get paginated results
        $waybills = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        // Calculate stats
        $stats = [
            'total' => Waybill::count(),
            'pending' => Waybill::where('status', 'PENDING')->count(),
            'dispatched' => Waybill::where('status', 'DISPATCHED')->count(),
            'delivered' => Waybill::where('status', 'DELIVERED')->count(),
            'returned' => Waybill::where('status', 'RETURNED')->count(),
        ];

        return Inertia::render('Waybills/Index', [
            'waybills' => $waybills,
            'filters' => $request->only(['search', 'status', 'courier']),
            'stats' => $stats,
        ]);
    }

    public function show(Waybill $waybill)
    {
        $waybill->load(['trackingHistory', 'lead']);

        return Inertia::render('Waybills/Show', [
            'waybill' => $waybill,
        ]);
    }

    public function updateStatus(Request $request, Waybill $waybill)
    {
        $request->validate([
            'status' => 'required|string',
            'reason' => 'nullable|string',
        ]);

        $previousStatus = $waybill->status;
        $waybill->status = $request->status;

        // Update timestamps based on status
        if ($request->status === 'DISPATCHED' && !$waybill->dispatched_at) {
            $waybill->dispatched_at = now();
        } elseif ($request->status === 'DELIVERED' && !$waybill->delivered_at) {
            $waybill->delivered_at = now();
        } elseif ($request->status === 'RETURNED' && !$waybill->returned_at) {
            $waybill->returned_at = now();
        }

        $waybill->save();

        // Create tracking history
        $waybill->trackingHistory()->create([
            'status' => $request->status,
            'previous_status' => $previousStatus,
            'reason' => $request->reason,
            'tracked_at' => now(),
        ]);

        return back()->with('success', 'Waybill status updated successfully');
    }
}
