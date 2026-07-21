<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Order\Services\DuplicateDetectionService;
use App\Domain\Shop\Services\CustomerMergeService;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Inertia\Inertia;

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
     * Check for fuzzy duplicate customers by name and address similarity.
     *
     * GET /api/duplicate-check/fuzzy-customers?customer_id=...&name_threshold=80&address_threshold=0.6
     */
    public function checkFuzzyCustomers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'name_threshold' => 'nullable|numeric|min:0|max:100',
            'address_threshold' => 'nullable|numeric|min:0|max:1',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $result = $this->service->detectFuzzyDuplicateCustomers(
            (int) $validated['customer_id'],
            isset($validated['name_threshold']) ? (float) $validated['name_threshold'] : 80.0,
            isset($validated['address_threshold']) ? (float) $validated['address_threshold'] : 0.6,
            isset($validated['limit']) ? (int) $validated['limit'] : 20,
        );

        return response()->json([
            'fuzzy_duplicates' => $result,
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

    /**
     * Render the duplicate review queue page.
     *
     * GET /shop/duplicate-review
     */
    public function reviewQueuePage(Request $request): Response
    {
        $filters = array_filter($request->only(['type', 'status', 'severity', 'per_page']));
        $queue = $this->service->getReviewQueue($filters);
        $stats = $this->service->getReviewQueueStats();

        return Inertia::render('DuplicateReview/Index', [
            'queue' => $queue['items'],
            'meta' => $queue['meta'],
            'stats' => $stats,
            'filters' => $filters,
        ]);
    }

    /**
     * Trigger a scan for duplicates and populate the review queue.
     *
     * POST /api/duplicate-check/scan
     */
    public function scanQueue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $result = $this->service->scanForReviewQueue(
            isset($validated['limit']) ? (int) $validated['limit'] : 50,
        );

        return response()->json($result);
    }

    /**
     * Get review queue items (API).
     *
     * GET /api/duplicate-check/review-queue
     */
    public function listQueue(Request $request): JsonResponse
    {
        $filters = array_filter($request->only(['type', 'status', 'severity', 'per_page']));

        $result = $this->service->getReviewQueue($filters);

        return response()->json($result);
    }

    /**
     * Resolve a review queue item.
     *
     * POST /api/duplicate-check/review-queue/{id}/resolve
     */
    public function resolveQueueItem(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:reviewed,dismissed,actioned',
            'note' => 'nullable|string|max:1000',
        ]);

        $item = $this->service->resolveReviewItem(
            $id,
            $validated['status'],
            $request->user()->id,
            $validated['note'] ?? null,
        );

        if (!$item) {
            return response()->json(['error' => 'Review item not found.'], 404);
        }

        return response()->json([
            'item' => [
                'id' => $item->id,
                'type' => $item->type,
                'status' => $item->status,
                'reviewed_by' => $item->reviewer?->name,
                'reviewed_at' => $item->reviewed_at?->toIso8601String(),
                'review_note' => $item->review_note,
            ],
        ]);
    }

    /**
     * Get review queue stats.
     *
     * GET /api/duplicate-check/review-queue/stats
     */
    public function queueStats(): JsonResponse
    {
        return response()->json($this->service->getReviewQueueStats());
    }
}
