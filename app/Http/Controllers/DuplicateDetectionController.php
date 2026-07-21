<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Order\Services\DuplicateDetectionService;
use App\Domain\Shop\Services\CustomerMergeService;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DuplicateDetectionController extends Controller
{
    public function __construct(
        private readonly DuplicateDetectionService $service,
        private readonly CustomerMergeService $customerMerge,
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

    /**
     * Check for duplicate conversations by PSID.
     *
     * GET /api/duplicate-check/conversations?psid=...&facebook_page_id=...&exclude_conversation_id=...
     */
    public function checkConversations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'psid' => 'required|string|max:255',
            'facebook_page_id' => 'nullable|integer',
            'exclude_conversation_id' => 'nullable|integer',
        ]);

        $result = $this->service->detectDuplicateConversationsByPsid(
            $validated['psid'],
            isset($validated['facebook_page_id']) ? (int) $validated['facebook_page_id'] : null,
            isset($validated['exclude_conversation_id']) ? (int) $validated['exclude_conversation_id'] : null,
        );

        return response()->json([
            'duplicate_conversations' => $result,
        ]);
    }

    /**
     * Check for duplicate customer records by phone, PSID, or name.
     *
     * GET /api/duplicate-check/customers?customer_id=...&methods=phone,psid,name
     */
    public function checkCustomers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'methods' => 'nullable|string',
        ]);

        $methods = isset($validated['methods'])
            ? array_filter(explode(',', $validated['methods']))
            : ['phone', 'psid', 'name'];

        $result = $this->service->detectDuplicateCustomers(
            (int) $validated['customer_id'],
            $methods,
        );

        return response()->json([
            'duplicate_customers' => $result,
        ]);
    }

    /**
     * Preview what will happen when merging source into target.
     *
     * GET /api/duplicate-check/merge-preview?target_id=...&source_id=...
     */
    public function mergePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_id' => 'required|integer|exists:customers,id',
            'source_id' => 'required|integer|exists:customers,id',
        ]);

        $preview = $this->service->previewMerge(
            (int) $validated['target_id'],
            (int) $validated['source_id'],
        );

        return response()->json($preview);
    }

    /**
     * Merge source customer into target customer.
     *
     * POST /api/duplicate-check/merge { target_id, source_id }
     */
    public function mergeCustomers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_id' => 'required|integer|exists:customers,id',
            'source_id' => 'required|integer|exists:customers,id|different:target_id',
        ]);

        $target = Customer::find($validated['target_id']);
        $source = Customer::find($validated['source_id']);

        $merged = $this->customerMerge->merge($target, $source);

        return response()->json([
            'merged' => true,
            'customer' => [
                'id' => $merged->id,
                'name' => $merged->name,
                'phone' => $merged->phone,
                'normalized_phone' => $merged->normalized_phone,
                'total_orders' => (int) ($merged->total_orders ?? 0),
                'total_revenue' => (float) ($merged->total_revenue ?? 0),
                'risk_level' => $merged->risk_level ?? 'LOW',
            ],
        ]);
    }
}
