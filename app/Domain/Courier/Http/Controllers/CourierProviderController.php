<?php

declare(strict_types=1);

namespace App\Domain\Courier\Http\Controllers;

use App\Domain\Courier\Actions\CreateCourierOrder;
use App\Domain\Courier\Models\CourierApiLog;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Courier\Services\CourierServiceManager;
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
}
