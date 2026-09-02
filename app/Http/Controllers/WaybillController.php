<?php

namespace App\Http\Controllers;

use Modules\Couriers\Jobs\BatchDispatchJob;
use Modules\Couriers\Models\CourierProvider;
use Modules\Couriers\Services\BatchDispatchService;
use Modules\Waybills\Models\DeliveryProof;
use Modules\Waybills\Services\DeliveryProofService;
use Modules\Waybills\Services\GeolocationMapService;
use Modules\Waybills\Services\QrCodeService;
use Modules\Waybills\Services\SlaDashboardService;
use App\Models\Customer;
use App\Models\Waybill;
use App\Services\SmsSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
        $waybill->load(['trackingHistory', 'lead', 'uploadedBy', 'deliveryProofs.uploader']);

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
            'deliveryProofs' => $waybill->deliveryProofs,
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
        if ($request->status === 'DISPATCHED' && ! $waybill->dispatched_at) {
            $waybill->dispatched_at = now();
        } elseif ($request->status === 'DELIVERED' && ! $waybill->delivered_at) {
            $waybill->delivered_at = now();
        } elseif ($request->status === 'RETURNED' && ! $waybill->returned_at) {
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

    public function search(Request $request)
    {
        $q = trim($request->query('q', ''));

        if (strlen($q) < 3) {
            return response()->json(['waybill' => null]);
        }

        $waybill = Waybill::where('waybill_number', 'ILIKE', "%{$q}%")
            ->select(['id', 'waybill_number', 'receiver_name', 'city', 'status', 'amount', 'cod_amount'])
            ->first();

        return response()->json(['waybill' => $waybill]);
    }

    public function batchDispatchPage(Request $request)
    {
        $pendingWaybills = Waybill::where('status', 'PENDING')
            ->orderBy('created_at', 'desc')
            ->paginate(50)
            ->withQueryString();

        $service = app(BatchDispatchService::class);
        $stats = $service->stats();

        $couriers = CourierProvider::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return Inertia::render('Waybills/BatchDispatch', [
            'pendingWaybills' => $pendingWaybills,
            'stats' => $stats,
            'couriers' => $couriers,
            'filters' => $request->only(['search']),
        ]);
    }

    public function batchDispatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'waybill_ids' => ['required', 'array', 'min:1', 'max:100'],
            'waybill_ids.*' => ['required', 'integer', 'exists:waybills,id'],
            'courier_code' => ['required', 'string', 'in:FLASH,JNT'],
            'async' => ['nullable', 'boolean'],
        ]);

        $senderDefaults = config('services.couriers.sender_defaults', []);

        if ($validated['async'] ?? false) {
            BatchDispatchJob::dispatch(
                $validated['waybill_ids'],
                $validated['courier_code'],
                $senderDefaults,
            );

            return response()->json([
                'success' => true,
                'async' => true,
                'message' => 'Batch dispatch queued for processing.',
                'count' => count($validated['waybill_ids']),
            ]);
        }

        $service = app(BatchDispatchService::class);
        $result = $service->dispatch($validated['waybill_ids'], $validated['courier_code'], $senderDefaults);

        return response()->json([
            'success' => $result['success'] > 0,
            'message' => "Dispatched {$result['success']}/{$result['total']} waybills to {$validated['courier_code']}."
                .($result['failed'] > 0 ? " {$result['failed']} failed." : ''),
            'result' => $result,
        ]);
    }

    public function batchDispatchStats(): JsonResponse
    {
        $service = app(BatchDispatchService::class);

        return response()->json($service->stats());
    }

    public function uploadDeliveryProof(Request $request, Waybill $waybill): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,gif,webp,pdf', 'max:10240'],
            'type' => ['nullable', 'string', 'in:photo,signature,pod_document,other'],
        ]);

        $service = app(DeliveryProofService::class);

        try {
            $proof = $service->storeUpload(
                $waybill,
                $request->file('file'),
                $validated['type'] ?? 'photo',
                $request->user()?->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Delivery proof uploaded.',
                'proof' => $proof->load('uploader'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function deleteDeliveryProof(Waybill $waybill, int $proofId): JsonResponse
    {
        $proof = DeliveryProof::where('waybill_id', $waybill->id)->findOrFail($proofId);

        $service = app(DeliveryProofService::class);
        $service->delete($proof);

        return response()->json(['success' => true, 'message' => 'Delivery proof deleted.']);
    }

    public function slaDashboard(Request $request): Response
    {
        $service = app(SlaDashboardService::class);
        $data = $service->getDashboardData($request->only(['courier', 'from', 'to']));

        return Inertia::render('Waybills/SlaDashboard', $data);
    }

    public function apiSlaDashboard(Request $request): JsonResponse
    {
        $service = app(SlaDashboardService::class);
        $data = $service->getDashboardData($request->only(['courier', 'from', 'to']));

        return response()->json($data);
    }

    public function updateSlaSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sla_return_days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        $service = app(SlaDashboardService::class);
        $settings = $service->updateSettings($validated);

        return response()->json([
            'success' => true,
            'message' => 'SLA settings updated.',
            'settings' => $settings,
        ]);
    }

    public function geoMap(Request $request): Response
    {
        $service = app(GeolocationMapService::class);
        $data = $service->getMapData($request->only(['courier', 'status']));
        $stats = $service->getStats();

        return Inertia::render('Waybills/GeoMap', array_merge($data, ['stats' => $stats]));
    }

    public function apiGeoMap(Request $request): JsonResponse
    {
        $service = app(GeolocationMapService::class);
        $data = $service->getMapData($request->only(['courier', 'status']));

        return response()->json($data);
    }

    public function geoMapHistory(Waybill $waybill): JsonResponse
    {
        $service = app(GeolocationMapService::class);
        $data = $service->getWaybillLocationHistory($waybill->id);

        return response()->json($data);
    }

    public function qrCode(Waybill $waybill): JsonResponse
    {
        $service = app(QrCodeService::class);
        $data = $service->getQrData($waybill);

        return response()->json($data);
    }

    public function qrCodeLabel(Waybill $waybill): \Illuminate\Http\Response
    {
        $service = app(QrCodeService::class);
        $data = $service->getLabelData($waybill);

        return response()->view('waybills.qr-label', $data);
    }
}
