<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\Conversation;
use App\Models\ReplyTemplate;
use App\Models\ReplyTemplateUsage;
use App\Models\ReplyTemplateVersion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReplyTemplateController extends Controller
{
    private const ROLE_OPTIONS = ['admin', 'supervisor', 'agent'];

    /**
     * Render the reply templates management page.
     *
     * GET /shop/reply-templates
     */
    public function index(Request $request): Response
    {
        $userId = $request->user()?->id;
        $userRole = $request->user()?->role;

        $query = ReplyTemplate::query()
            ->with(['facebookPage:id,page_name', 'creator:id,name', 'sharedPages:id,page_name', 'approver:id,name'])
            ->withExists(['favoritedBy as is_favorited' => fn ($q) => $q->where('user_id', $userId)])
            ->when($userRole && $userRole !== 'superadmin' && $userRole !== 'admin', function ($q) use ($userRole) {
                $q->where(function ($sub) use ($userRole) {
                    $sub->whereNull('allowed_roles')
                        ->orWhere('allowed_roles', 'like', '%"' . $userRole . '"%');
                });
            })
            ->when($request->query('search'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%")
                        ->orWhere('shortcut', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->when($request->query('page_id'), function ($q, $pageId) {
                $q->where(function ($sub) use ($pageId) {
                    $sub->where('facebook_page_id', $pageId)
                        ->orWhereNull('facebook_page_id')
                        ->orWhereHas('sharedPages', fn ($sp) => $sp->where('facebook_page_id', $pageId));
                });
            })
            ->when($request->query('category'), function ($q, $category) {
                $q->where('category', $category);
            })
            ->when($request->query('intent'), function ($q, $intent) {
                $q->where('intent', $intent);
            })
            ->when($request->query('approval_status'), function ($q, $status) {
                if ($status === 'null') {
                    $q->whereNull('approval_status');
                } else {
                    $q->where('approval_status', $status);
                }
            })
            ->when($request->boolean('favorites_only'), function ($q) use ($userId) {
                $q->whereHas('favoritedBy', fn ($sub) => $sub->where('user_id', $userId));
            })
            ->when($request->boolean('active_only', true), function ($q) {
                $q->where('is_active', true);
            })
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM reply_template_favorites WHERE reply_template_id = reply_templates.id AND user_id = ?) THEN 0 ELSE 1 END', [$userId])
            ->orderByDesc('usage_count')
            ->orderByDesc('created_at');

        $templates = $query->paginate(20)->withQueryString();
        $pages = FacebookPage::select('id', 'page_name')->orderBy('page_name')->get();

        // Get distinct categories and intents for filter dropdowns
        $categories = ReplyTemplate::query()
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->filter()
            ->values();

        $intents = ReplyTemplate::query()
            ->whereNotNull('intent')
            ->distinct()
            ->pluck('intent')
            ->filter()
            ->values();

        return Inertia::render('ReplyTemplates/Index', [
            'templates' => $templates,
            'pages' => $pages,
            'categories' => $categories,
            'intents' => $intents,
            'roles' => self::ROLE_OPTIONS,
            'analytics' => $this->usageAnalytics(),
            'performance' => $this->performanceAnalytics(),
            'approval_statuses' => ReplyTemplate::APPROVAL_STATUSES,
            'filters' => [
                'search' => $request->query('search', ''),
                'page_id' => $request->query('page_id', ''),
                'category' => $request->query('category', ''),
                'intent' => $request->query('intent', ''),
                'approval_status' => $request->query('approval_status', ''),
                'favorites_only' => $request->boolean('favorites_only'),
                'active_only' => $request->boolean('active_only', true),
            ],
        ]);
    }

    /**
     * Store a new reply template.
     *
     * POST /api/reply-templates
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:5000',
            'category' => 'nullable|string|max:100',
            'intent' => 'nullable|string|max:100',
            'allowed_roles' => 'nullable|array',
            'allowed_roles.*' => 'string|in:admin,supervisor,agent',
            'shortcut' => 'nullable|string|max:50|unique:reply_templates,shortcut',
            'facebook_page_id' => 'nullable|exists:facebook_pages,id',
            'shared_page_ids' => 'nullable|array',
            'shared_page_ids.*' => 'integer|exists:facebook_pages,id',
            'is_active' => 'boolean',
        ]);

        $validated['created_by'] = $request->user()->id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        // Auto-approve if creator is admin/superadmin; otherwise pending
        $creatorRole = $request->user()->role;
        if ($creatorRole === 'admin' || $creatorRole === 'superadmin') {
            $validated['approval_status'] = ReplyTemplate::APPROVAL_APPROVED;
            $validated['approved_by'] = $request->user()->id;
            $validated['approved_at'] = now();
        } else {
            $validated['approval_status'] = ReplyTemplate::APPROVAL_PENDING;
        }

        $sharedPageIds = $validated['shared_page_ids'] ?? [];
        unset($validated['shared_page_ids']);

        // Auto-detect variables from content
        preg_match_all('/\{(\w+)\}/', $validated['content'], $matches);
        $validated['variables'] = $matches[0] ?? [];

        $template = ReplyTemplate::create($validated);

        if (!empty($sharedPageIds)) {
            $template->sharedPages()->sync($sharedPageIds);
        }

        return response()->json([
            'success' => true,
            'template' => $template->fresh(['facebookPage', 'creator', 'sharedPages']),
        ], 201);
    }

    /**
     * Update a reply template.
     *
     * PUT /api/reply-templates/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $template = ReplyTemplate::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string|max:5000',
            'category' => 'sometimes|nullable|string|max:100',
            'intent' => 'sometimes|nullable|string|max:100',
            'allowed_roles' => 'sometimes|nullable|array',
            'allowed_roles.*' => 'string|in:admin,supervisor,agent',
            'shortcut' => 'sometimes|nullable|string|max:50|unique:reply_templates,shortcut,' . $id,
            'facebook_page_id' => 'sometimes|nullable|exists:facebook_pages,id',
            'shared_page_ids' => 'sometimes|nullable|array',
            'shared_page_ids.*' => 'integer|exists:facebook_pages,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $sharedPageIds = null;
        if (array_key_exists('shared_page_ids', $validated)) {
            $sharedPageIds = $validated['shared_page_ids'] ?? [];
            unset($validated['shared_page_ids']);
        }

        // Snapshot the current state before updating
        $this->createVersionSnapshot($template, $request->user()?->id);

        $template->update($validated);

        // If content/title changed, re-submit for approval (unless editor is admin)
        $editorRole = $request->user()?->role;
        $contentChanged = isset($validated['content']) || isset($validated['title']);
        if ($contentChanged && $editorRole !== 'admin' && $editorRole !== 'superadmin') {
            $template->update([
                'approval_status' => ReplyTemplate::APPROVAL_PENDING,
                'approved_by' => null,
                'approved_at' => null,
                'rejection_reason' => null,
            ]);
        } elseif ($contentChanged && ($editorRole === 'admin' || $editorRole === 'superadmin')) {
            $template->update([
                'approval_status' => ReplyTemplate::APPROVAL_APPROVED,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);
        }

        // Sync shared pages if provided
        if ($sharedPageIds !== null) {
            $template->sharedPages()->sync($sharedPageIds);
        }

        // Auto-detect variables if content was updated
        if (isset($validated['content'])) {
            preg_match_all('/\{(\w+)\}/', $validated['content'], $matches);
            $template->variables = $matches[0] ?? [];
            $template->save();
        }

        return response()->json([
            'success' => true,
            'template' => $template->fresh(['facebookPage', 'creator', 'sharedPages', 'approver']),
        ]);
    }

    /**
     * Delete a reply template (soft delete).
     *
     * DELETE /api/reply-templates/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $template = ReplyTemplate::findOrFail($id);
        $template->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Toggle active state.
     *
     * POST /api/reply-templates/{id}/toggle
     */
    public function toggle(int $id): JsonResponse
    {
        $template = ReplyTemplate::findOrFail($id);
        $template->update(['is_active' => !$template->is_active]);

        return response()->json([
            'success' => true,
            'is_active' => $template->is_active,
        ]);
    }

    /**
     * List templates as JSON (for use in conversation reply input).
     *
     * GET /api/reply-templates
     */
    public function list(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $userRole = $request->user()?->role;

        $query = ReplyTemplate::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('approval_status')
                    ->orWhere('approval_status', ReplyTemplate::APPROVAL_APPROVED);
            })
            ->withExists(['favoritedBy as is_favorited' => fn ($q) => $q->where('user_id', $userId)])
            ->when($userRole && $userRole !== 'superadmin' && $userRole !== 'admin', function ($q) use ($userRole) {
                $q->where(function ($sub) use ($userRole) {
                    $sub->whereNull('allowed_roles')
                        ->orWhere('allowed_roles', 'like', '%"' . $userRole . '"%');
                });
            })
            ->when($request->query('page_id'), function ($q, $pageId) {
                $q->where(function ($sub) use ($pageId) {
                    $sub->where('facebook_page_id', $pageId)
                        ->orWhereNull('facebook_page_id')
                        ->orWhereHas('sharedPages', fn ($sp) => $sp->where('facebook_page_id', $pageId));
                });
            })
            ->when($request->query('search'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', "%{$search}%")
                        ->orWhere('shortcut', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->when($request->query('category'), function ($q, $category) {
                $q->where('category', $category);
            })
            ->when($request->query('intent'), function ($q, $intent) {
                $q->where('intent', $intent);
            })
            ->when($request->boolean('favorites_only'), function ($q) use ($userId) {
                $q->whereHas('favoritedBy', fn ($sub) => $sub->where('user_id', $userId));
            })
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM reply_template_favorites WHERE reply_template_id = reply_templates.id AND user_id = ?) THEN 0 ELSE 1 END', [$userId])
            ->orderByDesc('usage_count')
            ->limit(50);

        return response()->json([
            'templates' => $query->get(['id', 'title', 'content', 'shortcut', 'category', 'intent', 'facebook_page_id', 'usage_count']),
        ]);
    }

    /**
     * Increment usage count when a template is used in a reply.
     *
     * POST /api/reply-templates/{id}/use
     */
    public function incrementUsage(Request $request, int $id): JsonResponse
    {
        $template = ReplyTemplate::find($id);

        if ($template) {
            $template->increment('usage_count');

            ReplyTemplateUsage::create([
                'reply_template_id' => $template->id,
                'user_id' => $request->user()?->id,
                'conversation_id' => $request->input('conversation_id'),
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'usage_count' => $template?->usage_count,
        ]);
    }

    /**
     * Get reply template usage analytics.
     *
     * GET /api/reply-templates/analytics
     */
    public function analytics(): JsonResponse
    {
        return response()->json($this->usageAnalytics());
    }

    private function usageAnalytics(): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfLast30 = Carbon::now()->subDays(30)->startOfDay();

        $topTemplates = ReplyTemplateUsage::query()
            ->select('reply_template_id', DB::raw('count(*) as usage_count'))
            ->with('replyTemplate:id,title')
            ->groupBy('reply_template_id')
            ->orderByDesc('usage_count')
            ->limit(10)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->reply_template_id,
                'title' => $u->replyTemplate?->title,
                'count' => $u->usage_count,
            ]);

        $topUsers = ReplyTemplateUsage::query()
            ->whereNotNull('user_id')
            ->select('user_id', DB::raw('count(*) as usage_count'))
            ->with('user:id,name')
            ->groupBy('user_id')
            ->orderByDesc('usage_count')
            ->limit(10)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->user_id,
                'name' => $u->user?->name,
                'count' => $u->usage_count,
            ]);

        $last30Days = ReplyTemplateUsage::query()
            ->where('created_at', '>=', $startOfLast30)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->mapWithKeys(fn ($u) => [$u->date => $u->count]);

        $dailySeries = collect(range(0, 29))
            ->map(function ($daysAgo) use ($startOfLast30) {
                $date = $startOfLast30->copy()->addDays($daysAgo)->format('Y-m-d');
                return ['date' => $date, 'count' => 0];
            })
            ->map(function ($item) use ($last30Days) {
                $item['count'] = $last30Days[$item['date']] ?? 0;
                return $item;
            })
            ->values();

        return [
            'total_uses' => ReplyTemplateUsage::count(),
            'uses_this_month' => ReplyTemplateUsage::where('created_at', '>=', $startOfMonth)->count(),
            'uses_last_30_days' => ReplyTemplateUsage::where('created_at', '>=', $startOfLast30)->count(),
            'top_templates' => $topTemplates,
            'top_users' => $topUsers,
            'daily_usage' => $dailySeries,
        ];
    }

    /**
     * Get reply template performance metrics.
     *
     * GET /api/reply-templates/performance
     */
    public function performanceMetrics(): JsonResponse
    {
        return response()->json($this->performanceAnalytics());
    }

    private function performanceAnalytics(): array
    {
        // Approval workflow stats
        $approvalStats = [
            'pending' => ReplyTemplate::where('approval_status', ReplyTemplate::APPROVAL_PENDING)->count(),
            'approved' => ReplyTemplate::where('approval_status', ReplyTemplate::APPROVAL_APPROVED)->count(),
            'rejected' => ReplyTemplate::where('approval_status', ReplyTemplate::APPROVAL_REJECTED)->count(),
            'no_status' => ReplyTemplate::whereNull('approval_status')->count(),
        ];

        // Average approval turnaround time (for approved templates)
        $avgApprovalTime = ReplyTemplate::whereNotNull('approved_at')
            ->whereNotNull('created_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (approved_at - created_at))) as avg_seconds')
            ->value('avg_seconds');

        // Rejection rate
        $totalDecided = $approvalStats['approved'] + $approvalStats['rejected'];
        $rejectionRate = $totalDecided > 0 ? round(($approvalStats['rejected'] / $totalDecided) * 100, 1) : 0;

        // Per-template performance: join usage with conversations
        $templatePerformance = ReplyTemplate::query()
            ->select('reply_templates.id', 'reply_templates.title', 'reply_templates.category', 'reply_templates.intent')
            ->selectRaw('COUNT(DISTINCT reply_template_usage.id) as total_uses')
            ->selectRaw('COUNT(DISTINCT reply_template_usage.user_id) as unique_users')
            ->selectRaw('COUNT(DISTINCT reply_template_usage.conversation_id) as unique_conversations')
            ->leftJoin('reply_template_usage', 'reply_templates.id', '=', 'reply_template_usage.reply_template_id')
            ->groupBy('reply_templates.id', 'reply_templates.title', 'reply_templates.category', 'reply_templates.intent')
            ->havingRaw('COUNT(DISTINCT reply_template_usage.id) > 0')
            ->orderByDesc('total_uses')
            ->limit(20)
            ->get()
            ->map(function ($t) {
                // Resolution rate for conversations where this template was used
                $resolvedCount = DB::table('reply_template_usage')
                    ->join('conversations', 'reply_template_usage.conversation_id', '=', 'conversations.id')
                    ->where('reply_template_usage.reply_template_id', $t->id)
                    ->whereNotNull('reply_template_usage.conversation_id')
                    ->where('conversations.status', 'resolved')
                    ->count();

                $convCount = (int) $t->unique_conversations;
                $resolutionRate = $convCount > 0 ? round(($resolvedCount / $convCount) * 100, 1) : 0;

                // Last used
                $lastUsed = DB::table('reply_template_usage')
                    ->where('reply_template_id', $t->id)
                    ->max('created_at');

                return [
                    'id' => $t->id,
                    'title' => $t->title,
                    'category' => $t->category,
                    'intent' => $t->intent,
                    'total_uses' => (int) $t->total_uses,
                    'unique_users' => (int) $t->unique_users,
                    'unique_conversations' => $convCount,
                    'resolved_conversations' => $resolvedCount,
                    'resolution_rate' => $resolutionRate,
                    'last_used' => $lastUsed,
                ];
            });

        // Category performance breakdown
        $categoryPerformance = ReplyTemplate::query()
            ->select('category')
            ->selectRaw('COUNT(*) as template_count')
            ->selectRaw('COALESCE(SUM(usage_count), 0) as total_usage')
            ->whereNotNull('category')
            ->groupBy('category')
            ->orderByDesc('total_usage')
            ->get()
            ->map(fn ($c) => [
                'category' => $c->category,
                'template_count' => (int) $c->template_count,
                'total_usage' => (int) $c->total_usage,
            ]);

        // Intent performance breakdown
        $intentPerformance = ReplyTemplate::query()
            ->select('intent')
            ->selectRaw('COUNT(*) as template_count')
            ->selectRaw('COALESCE(SUM(usage_count), 0) as total_usage')
            ->whereNotNull('intent')
            ->groupBy('intent')
            ->orderByDesc('total_usage')
            ->get()
            ->map(fn ($i) => [
                'intent' => $i->intent,
                'template_count' => (int) $i->template_count,
                'total_usage' => (int) $i->total_usage,
            ]);

        // Usage trend: compare last 7 days vs previous 7 days
        $last7Days = ReplyTemplateUsage::where('created_at', '>=', Carbon::now()->subDays(7))->count();
        $prev7Days = ReplyTemplateUsage::where('created_at', '>=', Carbon::now()->subDays(14))
            ->where('created_at', '<', Carbon::now()->subDays(7))
            ->count();
        $trendDirection = $prev7Days === 0 ? ($last7Days > 0 ? 'up' : 'flat') : ($last7Days > $prev7Days ? 'up' : ($last7Days < $prev7Days ? 'down' : 'flat'));
        $trendPercent = $prev7Days > 0 ? round((($last7Days - $prev7Days) / $prev7Days) * 100, 1) : 0;

        return [
            'approval_stats' => $approvalStats,
            'avg_approval_time_seconds' => $avgApprovalTime ? round((float) $avgApprovalTime) : null,
            'rejection_rate' => $rejectionRate,
            'template_performance' => $templatePerformance,
            'category_performance' => $categoryPerformance,
            'intent_performance' => $intentPerformance,
            'usage_trend' => [
                'last_7_days' => $last7Days,
                'prev_7_days' => $prev7Days,
                'direction' => $trendDirection,
                'percent_change' => $trendPercent,
            ],
        ];
    }

    /**
     * Suggest templates for a conversation based on context-aware ranking.
     *
     * GET /api/reply-templates/suggest?conversation_id={id}
     */
    public function suggestTemplates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'required|integer|exists:conversations,id',
        ]);

        $conversation = Conversation::with([
            'facebookPage:id,page_name',
            'customer:id,name,phone,normalized_phone',
            'assignedAgent:id,name',
        ])->findOrFail($validated['conversation_id']);

        $pageId = $conversation->facebook_page_id;
        $userRole = $request->user()?->role;
        $userId = $request->user()?->id;

        // Base query: active, approved/null, accessible by role, available for this page
        $templates = ReplyTemplate::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('approval_status')
                    ->orWhere('approval_status', ReplyTemplate::APPROVAL_APPROVED);
            })
            ->where(function ($q) use ($pageId) {
                $q->where('facebook_page_id', $pageId)
                    ->orWhereNull('facebook_page_id')
                    ->orWhereHas('sharedPages', fn ($sp) => $sp->where('facebook_page_id', $pageId));
            })
            ->when($userRole && $userRole !== 'superadmin' && $userRole !== 'admin', function ($q) use ($userRole) {
                $q->where(function ($sub) use ($userRole) {
                    $sub->whereNull('allowed_roles')
                        ->orWhere('allowed_roles', 'like', '%"' . $userRole . '"%');
                });
            })
            ->when($userId, function ($q) use ($userId) {
                $q->withExists(['favoritedBy as is_favorited' => fn ($sub) => $sub->where('user_id', $userId)]);
            })
            ->limit(100)
            ->get([
                'id', 'title', 'content', 'shortcut', 'category', 'intent',
                'facebook_page_id', 'usage_count', 'variables',
            ]);

        // Context signals from conversation
        $lastMessage = strtolower($conversation->last_message_preview ?? '');
        $convStatus = $conversation->status;
        $hasOrder = $conversation->customer?->total_orders > 0;

        // Intent → conversation status mapping
        $intentScores = [
            'new' => ['greeting' => 3, 'order_confirmation' => 2, 'faq' => 1],
            'assigned' => ['order_confirmation' => 3, 'shipping_update' => 3, 'payment_reminder' => 2, 'follow_up' => 2, 'faq' => 1],
            'awaiting_customer' => ['follow_up' => 3, 'payment_reminder' => 2, 'apology' => 1],
            'resolved' => ['closing' => 3, 'follow_up' => 1],
            'archived' => ['closing' => 1],
        ];

        // Keyword → intent mapping for last message content
        $keywordIntents = [
            'order' => 'order_confirmation',
            'confirm' => 'order_confirmation',
            'ship' => 'shipping_update',
            'delivery' => 'shipping_update',
            'track' => 'shipping_update',
            'pay' => 'payment_reminder',
            'payment' => 'payment_reminder',
            'cod' => 'payment_reminder',
            'gcash' => 'payment_reminder',
            'cancel' => 'apology',
            'return' => 'apology',
            'refund' => 'apology',
            'sorry' => 'apology',
            'problem' => 'escalation',
            'complaint' => 'escalation',
            'manager' => 'escalation',
            'thank' => 'closing',
            'thanks' => 'closing',
            'salamat' => 'closing',
            'hi' => 'greeting',
            'hello' => 'greeting',
            'po' => 'greeting',
        ];

        // Detect intent from last message keywords
        $detectedIntents = [];
        foreach ($keywordIntents as $keyword => $intent) {
            if (str_contains($lastMessage, $keyword)) {
                $detectedIntents[$intent] = ($detectedIntents[$intent] ?? 0) + 1;
            }
        }

        // Status-based intent scores
        $statusIntentScores = $intentScores[$convStatus] ?? [];

        // Score each template
        $scored = $templates->map(function (ReplyTemplate $template) use ($statusIntentScores, $detectedIntents, $lastMessage, $hasOrder, $userId) {
            $score = 0;
            $reasons = [];

            // 1. Intent match from conversation status
            if ($template->intent && isset($statusIntentScores[$template->intent])) {
                $points = $statusIntentScores[$template->intent];
                $score += $points;
                $reasons[] = "Matches conversation status ({$template->intent})";
            }

            // 2. Intent match from keyword detection
            if ($template->intent && isset($detectedIntents[$template->intent])) {
                $points = $detectedIntents[$template->intent] * 2;
                $score += $points;
                $reasons[] = "Keywords in last message match ({$template->intent})";
            }

            // 3. Content keyword overlap with last message
            $templateWords = array_unique(str_word_count(strtolower($template->content), 1));
            $messageWords = array_unique(str_word_count($lastMessage, 1));
            $overlap = count(array_intersect($templateWords, $messageWords));
            if ($overlap > 0) {
                $score += min($overlap, 3);
                if ($overlap >= 2) {
                    $reasons[] = "Content overlaps with customer message";
                }
            }

            // 4. Usage popularity (normalized 0-3)
            if ($template->usage_count > 0) {
                $popularityScore = min(3, round(log($template->usage_count + 1, 3)));
                $score += $popularityScore;
                if ($template->usage_count >= 10) {
                    $reasons[] = "Frequently used ({$template->usage_count}×)";
                }
            }

            // 5. Favorite boost
            if ($template->is_favorited ?? false) {
                $score += 1;
                $reasons[] = "In your favorites";
            }

            // 6. Order-related templates get boost if customer has orders
            if ($hasOrder && in_array($template->intent, ['order_confirmation', 'shipping_update', 'payment_reminder'])) {
                $score += 1;
                $reasons[] = "Customer has order history";
            }

            // 7. Greeting boost for new conversations with no agent replies yet
            if ($template->intent === 'greeting' && empty($lastMessage)) {
                $score += 2;
                $reasons[] = "Good opening message";
            }

            return [
                'id' => $template->id,
                'title' => $template->title,
                'content' => $template->content,
                'shortcut' => $template->shortcut,
                'category' => $template->category,
                'intent' => $template->intent,
                'usage_count' => $template->usage_count,
                'variables' => $template->variables,
                'is_favorited' => $template->is_favorited ?? false,
                'source' => 'reply_templates',
                'suggestion_score' => round($score, 1),
                'suggestion_reasons' => array_slice($reasons, 0, 3),
            ];
        })
        ->filter(fn ($t) => $t['suggestion_score'] > 0)
        ->sortByDesc('suggestion_score')
        ->take(5)
        ->values();

        return response()->json([
            'suggestions' => $scored,
            'conversation_status' => $convStatus,
            'detected_intents' => array_keys($detectedIntents),
        ]);
    }

    /**
     * Toggle favorite status for the authenticated user.
     *
     * POST /api/reply-templates/{id}/favorite
     */
    public function toggleFavorite(Request $request, int $id): JsonResponse
    {
        $template = ReplyTemplate::findOrFail($id);
        $user = $request->user();

        $isFavorited = $user->favoriteTemplates()->where('reply_template_id', $id)->exists();

        if ($isFavorited) {
            $user->favoriteTemplates()->detach($id);
        } else {
            $user->favoriteTemplates()->attach($id);
        }

        return response()->json([
            'success' => true,
            'is_favorited' => !$isFavorited,
        ]);
    }

    /**
     * Approve a pending template.
     *
     * POST /api/reply-templates/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $template = ReplyTemplate::findOrFail($id);

        if ($template->approval_status === ReplyTemplate::APPROVAL_APPROVED) {
            return response()->json([
                'success' => true,
                'message' => 'Template is already approved.',
            ]);
        }

        $template->update([
            'approval_status' => ReplyTemplate::APPROVAL_APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        return response()->json([
            'success' => true,
            'template' => $template->fresh(['facebookPage', 'creator', 'sharedPages', 'approver']),
        ]);
    }

    /**
     * Reject a pending template.
     *
     * POST /api/reply-templates/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $template = ReplyTemplate::findOrFail($id);

        $validated = $request->validate([
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        $template->update([
            'approval_status' => ReplyTemplate::APPROVAL_REJECTED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejection_reason' => $validated['rejection_reason'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'template' => $template->fresh(['facebookPage', 'creator', 'sharedPages', 'approver']),
        ]);
    }

    /**
     * List version history for a template.
     *
     * GET /api/reply-templates/{id}/versions
     */
    public function versions(int $id): JsonResponse
    {
        $template = ReplyTemplate::findOrFail($id);

        $versions = $template->versions()
            ->with('editor:id,name')
            ->get(['id', 'version_number', 'title', 'change_summary', 'edited_by', 'created_at'])
            ->map(fn (ReplyTemplateVersion $v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'title' => $v->title,
                'change_summary' => $v->change_summary,
                'edited_by' => $v->editor?->name,
                'created_at' => $v->created_at->toIso8601String(),
            ]);

        return response()->json([
            'versions' => $versions,
        ]);
    }

    /**
     * Get a specific version's full content.
     *
     * GET /api/reply-templates/{id}/versions/{versionId}
     */
    public function showVersion(int $id, int $versionId): JsonResponse
    {
        $template = ReplyTemplate::findOrFail($id);
        $version = $template->versions()->with('editor:id,name')->findOrFail($versionId);

        return response()->json([
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'title' => $version->title,
                'content' => $version->content,
                'variables' => $version->variables,
                'category' => $version->category,
                'intent' => $version->intent,
                'allowed_roles' => $version->allowed_roles,
                'shortcut' => $version->shortcut,
                'facebook_page_id' => $version->facebook_page_id,
                'is_active' => $version->is_active,
                'shared_page_ids' => $version->shared_page_ids,
                'change_summary' => $version->change_summary,
                'edited_by' => $version->editor?->name,
                'created_at' => $version->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Restore a template to a previous version.
     *
     * POST /api/reply-templates/{id}/versions/{versionId}/restore
     */
    public function restoreVersion(Request $request, int $id, int $versionId): JsonResponse
    {
        $template = ReplyTemplate::findOrFail($id);
        $version = $template->versions()->findOrFail($versionId);

        // Snapshot current state before restoring
        $this->createVersionSnapshot($template, $request->user()?->id, 'Restored to version ' . $version->version_number);

        $template->update([
            'title' => $version->title,
            'content' => $version->content,
            'variables' => $version->variables,
            'category' => $version->category,
            'intent' => $version->intent,
            'allowed_roles' => $version->allowed_roles,
            'shortcut' => $version->shortcut,
            'facebook_page_id' => $version->facebook_page_id,
            'is_active' => $version->is_active,
        ]);

        // Restore shared pages if available
        if ($version->shared_page_ids !== null) {
            $template->sharedPages()->sync($version->shared_page_ids);
        }

        return response()->json([
            'success' => true,
            'template' => $template->fresh(['facebookPage', 'creator', 'sharedPages']),
        ]);
    }

    private function createVersionSnapshot(ReplyTemplate $template, ?int $userId, ?string $changeSummary = null): void
    {
        $lastVersion = $template->versions()->max('version_number') ?? 0;

        ReplyTemplateVersion::create([
            'reply_template_id' => $template->id,
            'edited_by' => $userId,
            'version_number' => $lastVersion + 1,
            'title' => $template->title,
            'content' => $template->content,
            'variables' => $template->variables,
            'category' => $template->category,
            'intent' => $template->intent,
            'allowed_roles' => $template->allowed_roles,
            'shortcut' => $template->shortcut,
            'facebook_page_id' => $template->facebook_page_id,
            'is_active' => $template->is_active,
            'shared_page_ids' => $template->sharedPages()->pluck('facebook_pages.id')->toArray(),
            'change_summary' => $changeSummary,
        ]);
    }
}
