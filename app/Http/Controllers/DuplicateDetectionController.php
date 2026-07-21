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

    // ── Configuration Rules ──────────────────────────────────────────

    /**
     * Render the detection rules configuration page.
     *
     * GET /shop/duplicate-review/rules
     */
    public function rulesPage(): Response
    {
        $rules = $this->service->getAllRules();

        return Inertia::render('DuplicateReview/Rules', [
            'rules' => $rules->map(fn ($rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'type' => $rule->type,
                'match_method' => $rule->match_method,
                'is_enabled' => $rule->is_enabled,
                'priority' => $rule->priority,
                'config' => $rule->config,
                'description' => $rule->description,
                'created_by' => $rule->creator?->name,
                'updated_by' => $rule->updater?->name,
                'created_at' => $rule->created_at?->toIso8601String(),
                'updated_at' => $rule->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * List all detection rules (API).
     *
     * GET /api/duplicate-check/rules
     */
    public function listRules(): JsonResponse
    {
        $rules = $this->service->getAllRules();

        return response()->json([
            'rules' => $rules,
        ]);
    }

    /**
     * Create a new detection rule.
     *
     * POST /api/duplicate-check/rules
     */
    public function createRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:order,customer,conversation',
            'match_method' => 'nullable|string|max:100',
            'is_enabled' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0|max:999',
            'config' => 'nullable|array',
            'description' => 'nullable|string|max:1000',
        ]);

        $rule = $this->service->createRule($validated, $request->user()->id);

        return response()->json([
            'rule' => $rule,
        ], 201);
    }

    /**
     * Update a detection rule.
     *
     * PUT /api/duplicate-check/rules/{id}
     */
    public function updateRule(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'type' => 'nullable|in:order,customer,conversation',
            'match_method' => 'nullable|string|max:100',
            'is_enabled' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0|max:999',
            'config' => 'nullable|array',
            'description' => 'nullable|string|max:1000',
        ]);

        $rule = $this->service->updateRule($id, $validated, $request->user()->id);

        if (!$rule) {
            return response()->json(['error' => 'Rule not found.'], 404);
        }

        return response()->json([
            'rule' => $rule,
        ]);
    }

    /**
     * Delete a detection rule.
     *
     * DELETE /api/duplicate-check/rules/{id}
     */
    public function deleteRule(int $id): JsonResponse
    {
        $deleted = $this->service->deleteRule($id);

        if (!$deleted) {
            return response()->json(['error' => 'Rule not found.'], 404);
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * Toggle a rule's enabled status.
     *
     * POST /api/duplicate-check/rules/{id}/toggle
     */
    public function toggleRule(Request $request, int $id): JsonResponse
    {
        $rule = $this->service->toggleRule($id, $request->user()->id);

        if (!$rule) {
            return response()->json(['error' => 'Rule not found.'], 404);
        }

        return response()->json([
            'rule' => $rule,
        ]);
    }

    // ── Analytics ────────────────────────────────────────────────────

    /**
     * Render the duplicate analytics dashboard page.
     *
     * GET /shop/duplicate-review/analytics
     */
    public function analyticsPage(Request $request): Response
    {
        $days = (int) ($request->query('days', '30'));

        return Inertia::render('DuplicateReview/Analytics', [
            'overview' => $this->service->getAnalyticsOverview($days),
            'trend' => $this->service->getAnalyticsTrend($days),
            'breakdown' => $this->service->getAnalyticsBreakdown(),
            'days' => $days,
        ]);
    }

    /**
     * Get analytics overview (API).
     *
     * GET /api/duplicate-check/analytics/overview?days=30
     */
    public function analyticsOverview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        return response()->json(
            $this->service->getAnalyticsOverview(isset($validated['days']) ? (int) $validated['days'] : 30),
        );
    }

    /**
     * Get analytics trend (API).
     *
     * GET /api/duplicate-check/analytics/trend?days=30
     */
    public function analyticsTrend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        return response()->json([
            'trend' => $this->service->getAnalyticsTrend(isset($validated['days']) ? (int) $validated['days'] : 30),
        ]);
    }

    /**
     * Get analytics breakdown (API).
     *
     * GET /api/duplicate-check/analytics/breakdown
     */
    public function analyticsBreakdown(): JsonResponse
    {
        return response()->json(
            $this->service->getAnalyticsBreakdown(),
        );
    }

    // ── Auto-Merge Suggestions ───────────────────────────────────────

    /**
     * Render the auto-merge suggestions page.
     *
     * GET /shop/duplicate-review/auto-merge
     */
    public function autoMergePage(Request $request): Response
    {
        $filters = array_filter([
            'status' => $request->query('status'),
            'min_confidence' => $request->query('min_confidence'),
        ]);

        return Inertia::render('DuplicateReview/AutoMerge', [
            'suggestions' => $this->service->getAutoMergeSuggestions($filters),
            'stats' => $this->service->getAutoMergeStats(),
            'filters' => $filters,
        ]);
    }

    /**
     * Scan for auto-merge suggestions.
     *
     * POST /api/duplicate-check/auto-merge/scan
     */
    public function scanAutoMerge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $result = $this->service->scanForAutoMergeSuggestions(
            isset($validated['limit']) ? (int) $validated['limit'] : 100,
        );

        return response()->json($result);
    }

    /**
     * List auto-merge suggestions (API).
     *
     * GET /api/duplicate-check/auto-merge
     */
    public function listAutoMerge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:pending,merged,rejected',
            'min_confidence' => 'nullable|numeric|min:0|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(
            $this->service->getAutoMergeSuggestions($validated),
        );
    }

    /**
     * Approve and execute an auto-merge suggestion.
     *
     * POST /api/duplicate-check/auto-merge/{id}/approve
     */
    public function approveAutoMerge(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $result = $this->service->approveAutoMergeSuggestion(
            $id,
            $request->user()->id,
            $validated['note'] ?? null,
        );

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 400);
        }

        return response()->json($result);
    }

    /**
     * Reject an auto-merge suggestion.
     *
     * POST /api/duplicate-check/auto-merge/{id}/reject
     */
    public function rejectAutoMerge(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $suggestion = $this->service->rejectAutoMergeSuggestion(
            $id,
            $request->user()->id,
            $validated['note'] ?? null,
        );

        if (!$suggestion) {
            return response()->json(['error' => 'Suggestion not found.'], 404);
        }

        return response()->json(['suggestion' => $suggestion]);
    }

    /**
     * Get auto-merge stats (API).
     *
     * GET /api/duplicate-check/auto-merge/stats
     */
    public function autoMergeStats(): JsonResponse
    {
        return response()->json(
            $this->service->getAutoMergeStats(),
        );
    }
}
