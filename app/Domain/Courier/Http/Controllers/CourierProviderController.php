<?php

declare(strict_types=1);

namespace App\Domain\Courier\Http\Controllers;

use App\Domain\Courier\Actions\CreateCourierOrder;
use App\Domain\Courier\Models\CourierApiLog;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Courier\Services\CourierServiceManager;
use App\Domain\Courier\Services\RateComparisonService;
use App\Domain\Shop\Models\ShippingRate;
use App\Models\Waybill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CourierProviderController
{
    public function index(): Response
    {
        $providers = CourierProvider::withCount([
            'apiLogs as total_api_calls',
            'apiLogs as failed_api_calls' => fn ($q) => $q->where('is_success', false),
        ])
        ->get()
        ->map(function ($provider) {
            $lastLog = $provider->apiLogs()
                ->where('action', '!=', 'webhook')
                ->latest()
                ->first();

            $provider->last_api_call_at = $lastLog?->created_at;
            $provider->active_waybills = Waybill::where('courier_provider', $provider->code)
                ->whereNotIn('status', ['DELIVERED', 'RETURNED', 'CANCELLED'])
                ->count();

            return $provider;
        });

        $recentLogs = CourierApiLog::with('provider')
            ->latest()
            ->limit(20)
            ->get();

        return Inertia::render('Couriers/Index', [
            'providers'  => $providers,
            'recentLogs' => $recentLogs,
        ]);
    }

    public function update(Request $request, CourierProvider $provider): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'is_active'      => ['sometimes', 'boolean'],
            'api_endpoint'   => ['sometimes', 'nullable', 'string', 'url'],
            'webhook_secret' => ['sometimes', 'nullable', 'string'],
            'config'         => ['sometimes', 'nullable', 'array'],
        ]);

        $provider->update($validated);

        return back()->with('success', "{$provider->name} updated successfully.");
    }

    public function testConnection(CourierProvider $provider): JsonResponse
    {
        $manager = app(CourierServiceManager::class);

        try {
            $service = $manager->forProvider($provider);
            $result = $service->testConnection();

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'connected' => false,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    public function logs(Request $request, CourierProvider $provider): Response
    {
        $query = $provider->apiLogs()->latest();

        if ($request->has('action') && $request->action) {
            $query->where('action', $request->action);
        }

        if ($request->has('success') && $request->success !== null) {
            $query->where('is_success', $request->boolean('success'));
        }

        $logs = $query->paginate(50)->withQueryString();

        return Inertia::render('Couriers/Logs', [
            'provider' => $provider,
            'logs'     => $logs,
            'filters'  => $request->only(['action', 'success']),
        ]);
    }

    public function createOrder(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'waybill_id'    => ['required', 'exists:waybills,id'],
            'courier_code'  => ['required', 'string', 'in:FLASH,JNT'],
        ]);

        $waybill = Waybill::findOrFail($validated['waybill_id']);

        if ($waybill->status !== 'PENDING') {
            return back()->with('error', 'Only pending waybills can be submitted to a courier.');
        }

        $action = app(CreateCourierOrder::class);
        $senderDefaults = config('services.couriers.sender_defaults', []);
        $result = $action->execute($waybill, $validated['courier_code'], $senderDefaults);

        if ($result->success) {
            return back()->with('success', "Order submitted to {$validated['courier_code']}. Tracking: {$result->trackingNumber}");
        }

        return back()->with('error', "Failed to create order: {$result->errorMessage}");
    }

    public function syncTracking(CourierProvider $provider): JsonResponse
    {
        \App\Domain\Courier\Jobs\SyncTrackingStatusJob::dispatch($provider->code);

        return response()->json([
            'message' => "Tracking sync queued for {$provider->name}.",
        ]);
    }

    public function compareRates(): Response
    {
        $providers = CourierProvider::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return Inertia::render('Waybills/RateComparison', [
            'providers' => $providers,
        ]);
    }

    public function apiCompareRates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province'         => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
            'barangay'         => ['nullable', 'string', 'max:255'],
            'address'          => ['nullable', 'string', 'max:2000'],
            'weight'           => ['nullable', 'numeric', 'min:0'],
            'cod_amount'       => ['nullable', 'numeric', 'min:0'],
            'item_value'       => ['nullable', 'numeric', 'min:0'],
        ]);

        $service = app(RateComparisonService::class);

        $result = $service->compareRates(
            [
                'province'         => $validated['province'] ?? null,
                'city_municipality' => $validated['city_municipality'] ?? null,
                'barangay'         => $validated['barangay'] ?? null,
                'address'          => $validated['address'] ?? null,
            ],
            [
                'weight'     => (float) ($validated['weight'] ?? 0),
                'cod_amount' => (float) ($validated['cod_amount'] ?? 0),
                'item_value' => (float) ($validated['item_value'] ?? 0),
            ]
        );

        return response()->json($result);
    }

    public function rateManagement(): Response
    {
        $service = app(RateComparisonService::class);
        $rates = $service->getAllRates();

        $providers = CourierProvider::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return Inertia::render('Waybills/RateManagement', [
            'rates' => $rates,
            'providers' => $providers,
        ]);
    }

    public function storeRate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'courier_code'       => ['required', 'string', 'max:30'],
            'courier_zone'       => ['required', 'string', 'max:50'],
            'base_fee'           => ['required', 'numeric', 'min:0'],
            'per_kg_fee'         => ['nullable', 'numeric', 'min:0'],
            'weight_threshold_kg' => ['nullable', 'numeric', 'min:0'],
            'cod_fee'            => ['nullable', 'numeric', 'min:0'],
            'is_active'          => ['boolean'],
        ]);

        $rate = ShippingRate::updateOrCreate(
            [
                'courier_code' => $validated['courier_code'],
                'courier_zone' => $validated['courier_zone'],
            ],
            [
                'base_fee'           => $validated['base_fee'],
                'per_kg_fee'         => $validated['per_kg_fee'] ?? 0,
                'weight_threshold_kg' => $validated['weight_threshold_kg'] ?? 0,
                'cod_fee'            => $validated['cod_fee'] ?? 0,
                'is_active'          => $validated['is_active'] ?? true,
            ]
        );

        return response()->json([
            'success' => true,
            'rate' => $rate,
            'message' => 'Rate saved successfully.',
        ]);
    }

    public function updateRate(Request $request, ShippingRate $rate): JsonResponse
    {
        $validated = $request->validate([
            'base_fee'           => ['sometimes', 'numeric', 'min:0'],
            'per_kg_fee'         => ['sometimes', 'numeric', 'min:0'],
            'weight_threshold_kg' => ['sometimes', 'numeric', 'min:0'],
            'cod_fee'            => ['sometimes', 'numeric', 'min:0'],
            'is_active'          => ['sometimes', 'boolean'],
        ]);

        $rate->update($validated);

        return response()->json([
            'success' => true,
            'rate' => $rate->fresh(),
            'message' => 'Rate updated successfully.',
        ]);
    }

    public function destroyRate(ShippingRate $rate): JsonResponse
    {
        $rate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rate deleted successfully.',
        ]);
    }
}
