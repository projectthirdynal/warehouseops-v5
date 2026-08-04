<?php

namespace App\Http\Controllers;

use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushNotificationController extends Controller
{
    public function __construct(
        private PushNotificationService $pushService
    ) {}

    /**
     * Subscribe to push notifications.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string|url',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
            'contentEncoding' => 'nullable|string|in:aesgcm,aes128gcm',
        ]);

        $this->pushService->subscribe($request->user(), $validated);

        return response()->json(['success' => true]);
    }

    /**
     * Unsubscribe from push notifications.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $this->pushService->unsubscribe($request->user(), $validated['endpoint']);

        return response()->json(['success' => true]);
    }

    /**
     * Get subscription status.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json($this->pushService->getStats($request->user()));
    }
}
