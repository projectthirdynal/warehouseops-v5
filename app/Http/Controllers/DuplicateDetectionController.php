<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Order\Services\DuplicateDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DuplicateDetectionController extends Controller
{
    public function __construct(
        private readonly DuplicateDetectionService $service,
    ) {}

    /**
     * Check for duplicate orders by phone + product IDs.
     *
     * GET /api/duplicate-check/orders?phone=...&product_ids=1,2,3&time_window_hours=72
     */
    public function checkOrders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:30',
            'product_ids' => 'required|string',
            'time_window_hours' => 'nullable|integer|min:1|max:720',
            'exclude_order_id' => 'nullable|integer',
        ]);

        $productIds = collect(explode(',', $validated['product_ids']))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $result = $this->service->detectDuplicateOrders(
            $validated['phone'],
            $productIds,
            isset($validated['time_window_hours']) ? (int) $validated['time_window_hours'] : null,
            isset($validated['exclude_order_id']) ? (int) $validated['exclude_order_id'] : null,
        );

        return response()->json([
            'duplicate_check' => $result,
        ]);
    }

    /**
     * Check for recent orders by phone (broader check for create-order page).
     *
     * GET /api/duplicate-check/recent?phone=...&days=30
     */
    public function checkRecent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:30',
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        $result = $this->service->checkRecentOrdersByPhone(
            $validated['phone'],
            isset($validated['days']) ? (int) $validated['days'] : null,
        );

        return response()->json([
            'recent_orders' => $result,
        ]);
    }
}
