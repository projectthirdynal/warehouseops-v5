<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Waybill;
use App\Services\SmsSequenceService;
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
        $waybill->load(['trackingHistory', 'lead', 'uploadedBy']);

        // Find or create customer by phone
        $customer = Customer::where('phone', $waybill->receiver_phone)->first();

        // Get all orders for this customer (by phone number)
        $orderHistory = Waybill::where('receiver_phone', $waybill->receiver_phone)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'waybill_number', 'status', 'cod_amount', 'remarks', 'created_at', 'delivered_at', 'returned_at']);

        // Calculate customer stats from waybills
        $customerStats = [
            'total_orders' => $orderHistory->count(),
            'delivered' => $orderHistory->where('status', 'DELIVERED')->count(),
            'returned' => $orderHistory->where('status', 'RETURNED')->count(),
            'pending' => $orderHistory->whereIn('status', ['PENDING', 'IN_TRANSIT', 'DISPATCHED', 'OUT_FOR_DELIVERY'])->count(),
            'total_cod' => $orderHistory->sum('cod_amount'),
            'success_rate' => $orderHistory->count() > 0
                ? round($orderHistory->where('status', 'DELIVERED')->count() / $orderHistory->count() * 100, 1)
                : 0,
        ];

        // Determine customer rating based on success rate
        $rating = match (true) {
            $customerStats['success_rate'] >= 90 => ['score' => 5, 'label' => 'Excellent', 'color' => 'green'],
            $customerStats['success_rate'] >= 75 => ['score' => 4, 'label' => 'Good', 'color' => 'blue'],
            $customerStats['success_rate'] >= 50 => ['score' => 3, 'label' => 'Average', 'color' => 'yellow'],
            $customerStats['success_rate'] >= 25 => ['score' => 2, 'label' => 'Poor', 'color' => 'orange'],
            default => ['score' => 1, 'label' => 'High Risk', 'color' => 'red'],
        };

        return Inertia::render('Waybills/Show', [
            'waybill' => $waybill,
            'customer' => $customer,
            'orderHistory' => $orderHistory,
            'customerStats' => $customerStats,
            'customerRating' => $rating,
        ]);
    }

    public function updateStatus(Request $request, Waybill $waybill, SmsSequenceService $sequenceService)
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

        // Trigger SMS sequences based on status change
        $eventMap = [
            'DISPATCHED' => 'waybill_dispatched',
            'OUT_FOR_DELIVERY' => 'waybill_out_for_delivery',
            'DELIVERED' => 'waybill_delivered',
            'RETURNED' => 'waybill_returned',
        ];

        if (isset($eventMap[$request->status])) {
            $sequenceService->trigger($eventMap[$request->status], $waybill);
        }

        return back()->with('success', 'Waybill status updated successfully');
    }
}
