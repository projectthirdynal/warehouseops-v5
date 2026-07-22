<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shop\Models\FacebookPage;
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
            ->with(['facebookPage:id,page_name', 'creator:id,name', 'sharedPages:id,page_name'])
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
            'filters' => [
                'search' => $request->query('search', ''),
                'page_id' => $request->query('page_id', ''),
                'category' => $request->query('category', ''),
                'intent' => $request->query('intent', ''),
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
            'template' => $template->fresh(['facebookPage', 'creator', 'sharedPages']),
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
