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

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'merge',
            'entity_type' => 'customer',
            'entity_id' => $validated['target_id'],
            'entity_label' => $merged->name ?? "Customer #{$validated['target_id']}",
            'before_state' => ['source_id' => $validated['source_id'], 'source_name' => $source->name],
            'after_state' => ['merged_id' => $merged->id, 'total_orders' => (int) ($merged->total_orders ?? 0)],
            'note' => "Merged customer #{$validated['source_id']} into #{$validated['target_id']}",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

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

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'review',
            'entity_type' => 'review_item',
            'entity_id' => $item->id,
            'entity_label' => $item->primary_label,
            'after_state' => ['status' => $validated['status']],
            'note' => $validated['note'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

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

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'rule_create',
            'entity_type' => 'rule',
            'entity_id' => $rule->id,
            'entity_label' => $rule->name,
            'after_state' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

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

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'rule_update',
            'entity_type' => 'rule',
            'entity_id' => $id,
            'entity_label' => $rule->name,
            'after_state' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

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

        $this->service->logAction([
            'action' => 'rule_delete',
            'entity_type' => 'rule',
            'entity_id' => $id,
            'note' => 'Rule deleted',
        ]);

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

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'rule_toggle',
            'entity_type' => 'rule',
            'entity_id' => $id,
            'entity_label' => $rule->name,
            'after_state' => ['is_enabled' => $rule->is_enabled],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

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

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'scan',
            'entity_type' => 'auto_merge_suggestion',
            'after_state' => $result,
            'note' => 'Auto-merge scan triggered',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

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

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'auto_merge_approve',
            'entity_type' => 'auto_merge_suggestion',
            'entity_id' => $id,
            'after_state' => $result,
            'note' => $validated['note'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

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

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'auto_merge_reject',
            'entity_type' => 'auto_merge_suggestion',
            'entity_id' => $id,
            'note' => $validated['note'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

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

    // ── Duplicate Family Grouping ────────────────────────────────────

    /**
     * Render the duplicate families page.
     *
     * GET /shop/duplicate-review/families
     */
    public function familiesPage(Request $request): Response
    {
        $filters = array_filter([
            'status' => $request->query('status'),
            'method' => $request->query('method'),
            'min_members' => $request->query('min_members'),
        ]);

        return Inertia::render('DuplicateReview/Families', [
            'families' => $this->service->getFamilies($filters),
            'stats' => $this->service->getFamilyStats(),
            'filters' => $filters,
        ]);
    }

    /**
     * Build duplicate families (scan).
     *
     * POST /api/duplicate-check/families/build
     */
    public function buildFamilies(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $result = $this->service->buildFamilies(
            isset($validated['limit']) ? (int) $validated['limit'] : 100,
        );

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'family_build',
            'entity_type' => 'family',
            'after_state' => $result,
            'note' => 'Family build triggered',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($result);
    }

    /**
     * List duplicate families (API).
     *
     * GET /api/duplicate-check/families
     */
    public function listFamilies(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:active,merged,dismissed',
            'method' => 'nullable|string|in:phone,psid',
            'min_members' => 'nullable|integer|min:2',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(
            $this->service->getFamilies($validated),
        );
    }

    /**
     * Get a single family with members and merge previews.
     *
     * GET /api/duplicate-check/families/{id}
     */
    public function familyDetail(int $id): JsonResponse
    {
        $detail = $this->service->getFamilyDetail($id);

        if (!$detail) {
            return response()->json(['error' => 'Family not found.'], 404);
        }

        return response()->json($detail);
    }

    /**
     * Merge all non-anchor members of a family into the anchor.
     *
     * POST /api/duplicate-check/families/{id}/merge
     */
    public function mergeFamily(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $result = $this->service->mergeFamily(
            $id,
            $request->user()->id,
            $validated['note'] ?? null,
        );

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 400);
        }

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'family_merge',
            'entity_type' => 'family',
            'entity_id' => $id,
            'after_state' => $result,
            'note' => $validated['note'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($result);
    }

    /**
     * Dismiss a family without merging.
     *
     * POST /api/duplicate-check/families/{id}/dismiss
     */
    public function dismissFamily(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $family = $this->service->dismissFamily(
            $id,
            $request->user()->id,
            $validated['note'] ?? null,
        );

        if (!$family) {
            return response()->json(['error' => 'Family not found.'], 404);
        }

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'family_dismiss',
            'entity_type' => 'family',
            'entity_id' => $id,
            'entity_label' => $family->anchor_label,
            'note' => $validated['note'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['family' => $family]);
    }

    /**
     * Get family stats (API).
     *
     * GET /api/duplicate-check/families/stats
     */
    public function familyStats(): JsonResponse
    {
        return response()->json(
            $this->service->getFamilyStats(),
        );
    }

    // ── Duplicate Notifications ──────────────────────────────────────

    /**
     * Render the duplicate notifications page.
     *
     * GET /shop/duplicate-review/notifications
     */
    public function notificationsPage(Request $request): Response
    {
        $filters = array_filter([
            'type' => $request->query('type'),
            'severity' => $request->query('severity'),
            'unread_only' => $request->boolean('unread_only') ? '1' : null,
        ]);

        $userId = $request->user()?->id;

        return Inertia::render('DuplicateReview/Notifications', [
            'notifications' => $this->service->getNotifications(array_merge($filters, ['user_id' => $userId])),
            'stats' => $this->service->getNotificationStats($userId),
            'filters' => $filters,
        ]);
    }

    /**
     * Generate notifications from current duplicate state.
     *
     * POST /api/duplicate-check/notifications/generate
     */
    public function generateNotifications(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supervisor_id' => 'nullable|integer|exists:users,id',
        ]);

        $result = $this->service->generateNotificationsFromScan(
            isset($validated['supervisor_id']) ? (int) $validated['supervisor_id'] : null,
        );

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'notification_generate',
            'entity_type' => 'notification',
            'after_state' => $result,
            'note' => 'Notification generation triggered',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($result);
    }

    /**
     * List notifications (API).
     *
     * GET /api/duplicate-check/notifications
     */
    public function listNotifications(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'nullable|string|in:review_item,auto_merge,family,high_severity',
            'severity' => 'nullable|string|in:low,medium,high,critical',
            'unread_only' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $validated['user_id'] = $request->user()?->id;

        return response()->json(
            $this->service->getNotifications($validated),
        );
    }

    /**
     * Mark a single notification as read.
     *
     * POST /api/duplicate-check/notifications/{id}/read
     */
    public function markNotificationRead(Request $request, int $id): JsonResponse
    {
        $notification = $this->service->markNotificationRead($id, $request->user()->id);

        if (!$notification) {
            return response()->json(['error' => 'Notification not found.'], 404);
        }

        return response()->json(['notification' => $notification]);
    }

    /**
     * Mark all notifications as read for the current user.
     *
     * POST /api/duplicate-check/notifications/mark-all-read
     */
    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $count = $this->service->markAllNotificationsRead($request->user()->id);

        return response()->json(['marked_read' => $count]);
    }

    /**
     * Get notification stats (API).
     *
     * GET /api/duplicate-check/notifications/stats
     */
    public function notificationStats(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;

        return response()->json(
            $this->service->getNotificationStats($userId),
        );
    }

    // ── Duplicate Audit Log ──────────────────────────────────────────

    /**
     * Render the duplicate audit log page.
     *
     * GET /shop/duplicate-review/audit-log
     */
    public function auditLogPage(Request $request): Response
    {
        $filters = array_filter($request->only(['action', 'entity_type', 'from', 'to']));
        $days = (int) ($request->query('days', '30'));

        return Inertia::render('DuplicateReview/AuditLog', [
            'logs' => $this->service->getAuditLogs($filters),
            'stats' => $this->service->getAuditLogStats($days),
            'filters' => $filters,
            'days' => $days,
        ]);
    }

    /**
     * List audit logs (API).
     *
     * GET /api/duplicate-check/audit-log
     */
    public function listAuditLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'nullable|string',
            'entity_type' => 'nullable|string',
            'entity_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(
            $this->service->getAuditLogs($validated),
        );
    }

    /**
     * Get audit log stats (API).
     *
     * GET /api/duplicate-check/audit-log/stats?days=30
     */
    public function auditLogStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        return response()->json(
            $this->service->getAuditLogStats(isset($validated['days']) ? (int) $validated['days'] : 30),
        );
    }

    /**
     * Download audit logs as CSV.
     *
     * GET /api/duplicate-check/audit-log/export
     */
    public function exportAuditLogs(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filters = array_filter($request->only(['action', 'entity_type', 'from', 'to']));

        $csv = $this->service->exportAuditLogsCsv($filters);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'duplicate-audit-log-' . date('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="duplicate-audit-log-' . date('Y-m-d') . '.csv"',
        ]);
    }

    // ── Cross-Page Duplicate Detection ───────────────────────────────

    /**
     * Render the cross-page duplicate detection page.
     *
     * GET /shop/duplicate-review/cross-page
     */
    public function crossPagePage(Request $request): Response
    {
        $result = $this->service->scanCrossPageDuplicates(
            (int) ($request->query('limit', '100')),
        );
        $stats = $this->service->getCrossPageStats();

        return Inertia::render('DuplicateReview/CrossPage', [
            'groups' => $result['groups'],
            'totalGroups' => $result['total_groups'],
            'stats' => $stats,
        ]);
    }

    /**
     * Detect cross-page duplicates for a specific phone or PSID.
     *
     * GET /api/duplicate-check/cross-page/detect?phone=...&psid=...
     */
    public function detectCrossPage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'nullable|string|max:30',
            'psid' => 'nullable|string|max:100',
            'time_window_hours' => 'nullable|integer|min:1|max:720',
        ]);

        if (empty($validated['phone']) && empty($validated['psid'])) {
            return response()->json(['error' => 'Either phone or psid is required.'], 422);
        }

        return response()->json(
            $this->service->detectCrossPageDuplicates($validated),
        );
    }

    /**
     * Scan for all cross-page duplicates.
     *
     * POST /api/duplicate-check/cross-page/scan
     */
    public function scanCrossPage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $result = $this->service->scanCrossPageDuplicates(
            isset($validated['limit']) ? (int) $validated['limit'] : 100,
        );

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'scan',
            'entity_type' => 'cross_page',
            'after_state' => $result,
            'note' => 'Cross-page duplicate scan triggered',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($result);
    }

    /**
     * Get cross-page duplicate stats.
     *
     * GET /api/duplicate-check/cross-page/stats
     */
    public function crossPageStats(): JsonResponse
    {
        return response()->json(
            $this->service->getCrossPageStats(),
        );
    }

    // ── Duplicate Export ─────────────────────────────────────────────

    /**
     * Render the duplicate export page.
     *
     * GET /shop/duplicate-review/export
     */
    public function exportPage(Request $request): Response
    {
        $stats = $this->service->getReviewQueueStats();
        $autoMergeStats = $this->service->getAutoMergeStats();
        $familyStats = $this->service->getFamilyStats();
        $crossPageStats = $this->service->getCrossPageStats();
        $auditLogStats = $this->service->getAuditLogStats(30);

        return Inertia::render('DuplicateReview/Export', [
            'reviewQueueStats' => $stats,
            'autoMergeStats' => $autoMergeStats,
            'familyStats' => $familyStats,
            'crossPageStats' => $crossPageStats,
            'auditLogStats' => $auditLogStats,
        ]);
    }

    /**
     * Export review queue as CSV.
     *
     * GET /api/duplicate-check/export/review-queue
     */
    public function exportReviewQueue(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filters = array_filter($request->only(['type', 'status', 'severity']));
        $csv = $this->service->exportReviewQueueCsv($filters);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'duplicate-review-queue-' . date('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="duplicate-review-queue-' . date('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Export auto-merge suggestions as CSV.
     *
     * GET /api/duplicate-check/export/auto-merge
     */
    public function exportAutoMerge(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filters = array_filter($request->only(['status', 'min_confidence']));
        $csv = $this->service->exportAutoMergeSuggestionsCsv($filters);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'duplicate-auto-merge-' . date('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="duplicate-auto-merge-' . date('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Export duplicate families as CSV.
     *
     * GET /api/duplicate-check/export/families
     */
    public function exportFamilies(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filters = array_filter($request->only(['status', 'method']));
        $csv = $this->service->exportFamiliesCsv($filters);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'duplicate-families-' . date('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="duplicate-families-' . date('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Export cross-page duplicates as CSV.
     *
     * GET /api/duplicate-check/export/cross-page
     */
    public function exportCrossPage(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $csv = $this->service->exportCrossPageCsv();

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'duplicate-cross-page-' . date('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="duplicate-cross-page-' . date('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Export all duplicates as a combined CSV report.
     *
     * GET /api/duplicate-check/export/all
     */
    public function exportAll(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $csv = $this->service->exportAllDuplicatesCsv();

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'export',
            'entity_type' => 'duplicate_report',
            'note' => 'Full duplicate export downloaded',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'duplicate-report-' . date('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="duplicate-report-' . date('Y-m-d') . '.csv"',
        ]);
    }

    // ── ML-Based Duplicate Scoring ───────────────────────────────────

    /**
     * Render the ML scoring page.
     *
     * GET /shop/duplicate-review/ml-scoring
     */
    public function mlScoringPage(Request $request): Response
    {
        $minScore = (float) ($request->query('min_score', '70'));
        $limit = (int) ($request->query('limit', '50'));

        $scanResult = $this->service->scanMlDuplicates($minScore, $limit);
        $stats = $this->service->getMlModelStats();

        return Inertia::render('DuplicateReview/MLScoring', [
            'pairs' => $scanResult['pairs'],
            'totalPairs' => $scanResult['total_pairs'],
            'modelVersion' => $scanResult['model_version'],
            'modelStats' => $stats,
            'minScore' => $minScore,
        ]);
    }

    /**
     * Score a specific customer pair.
     *
     * GET /api/duplicate-check/ml/score?customer_a=1&customer_b=2
     */
    public function scorePair(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_a' => 'required|integer|exists:customers,id',
            'customer_b' => 'required|integer|exists:customers,id',
        ]);

        $a = Customer::find($validated['customer_a']);
        $b = Customer::find($validated['customer_b']);

        return response()->json(
            $this->service->scorePair($a, $b),
        );
    }

    /**
     * Scan for ML-scored duplicate pairs.
     *
     * POST /api/duplicate-check/ml/scan
     */
    public function scanMl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'min_score' => 'nullable|numeric|min:0|max:100',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $result = $this->service->scanMlDuplicates(
            isset($validated['min_score']) ? (float) $validated['min_score'] : 70.0,
            isset($validated['limit']) ? (int) $validated['limit'] : 100,
        );

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'scan',
            'entity_type' => 'ml_scoring',
            'after_state' => $result,
            'note' => 'ML duplicate scan triggered',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($result);
    }

    /**
     * Train the ML model.
     *
     * POST /api/duplicate-check/ml/train
     */
    public function trainMlModel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'epochs' => 'nullable|integer|min:10|max:1000',
            'learning_rate' => 'nullable|numeric|min:0.001|max:1.0',
        ]);

        $result = $this->service->trainModel(
            isset($validated['epochs']) ? (int) $validated['epochs'] : 100,
            isset($validated['learning_rate']) ? (float) $validated['learning_rate'] : 0.01,
        );

        $this->service->logAction([
            'user_id' => $request->user()->id,
            'action' => 'ml_train',
            'entity_type' => 'ml_model',
            'after_state' => $result,
            'note' => 'ML model training triggered',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($result);
    }

    /**
     * Get ML model stats.
     *
     * GET /api/duplicate-check/ml/stats
     */
    public function mlModelStats(): JsonResponse
    {
        return response()->json(
            $this->service->getMlModelStats(),
        );
    }
}
