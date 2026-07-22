<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shop\Models\FacebookPage;
use App\Models\ReplyTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReplyTemplateController extends Controller
{
    /**
     * Render the reply templates management page.
     *
     * GET /shop/reply-templates
     */
    public function index(Request $request): Response
    {
        $query = ReplyTemplate::query()
            ->with(['facebookPage:id,page_name', 'creator:id,name'])
            ->when($request->query('search'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%")
                        ->orWhere('shortcut', 'like', "%{$search}%");
                });
            })
            ->when($request->query('page_id'), function ($q, $pageId) {
                $q->where('facebook_page_id', $pageId);
            })
            ->when($request->query('category'), function ($q, $category) {
                $q->where('category', $category);
            })
            ->when($request->query('intent'), function ($q, $intent) {
                $q->where('intent', $intent);
            })
            ->when($request->boolean('active_only', true), function ($q) {
                $q->where('is_active', true);
            })
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
            'filters' => [
                'search' => $request->query('search', ''),
                'page_id' => $request->query('page_id', ''),
                'category' => $request->query('category', ''),
                'intent' => $request->query('intent', ''),
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
            'shortcut' => 'nullable|string|max:50|unique:reply_templates,shortcut',
            'facebook_page_id' => 'nullable|exists:facebook_pages,id',
            'is_active' => 'boolean',
        ]);

        $validated['created_by'] = $request->user()->id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        // Auto-detect variables from content
        preg_match_all('/\{(\w+)\}/', $validated['content'], $matches);
        $validated['variables'] = $matches[0] ?? [];

        $template = ReplyTemplate::create($validated);

        return response()->json([
            'success' => true,
            'template' => $template,
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
            'shortcut' => 'sometimes|nullable|string|max:50|unique:reply_templates,shortcut,' . $id,
            'facebook_page_id' => 'sometimes|nullable|exists:facebook_pages,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $template->update($validated);

        // Auto-detect variables if content was updated
        if (isset($validated['content'])) {
            preg_match_all('/\{(\w+)\}/', $validated['content'], $matches);
            $template->variables = $matches[0] ?? [];
            $template->save();
        }

        return response()->json([
            'success' => true,
            'template' => $template->fresh(['facebookPage', 'creator']),
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
        $query = ReplyTemplate::query()
            ->where('is_active', true)
            ->when($request->query('page_id'), function ($q, $pageId) {
                $q->where(function ($sub) use ($pageId) {
                    $sub->where('facebook_page_id', $pageId)
                        ->orWhereNull('facebook_page_id');
                });
            })
            ->when($request->query('search'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', "%{$search}%")
                        ->orWhere('shortcut', 'like', "%{$search}%");
                });
            })
            ->when($request->query('category'), function ($q, $category) {
                $q->where('category', $category);
            })
            ->when($request->query('intent'), function ($q, $intent) {
                $q->where('intent', $intent);
            })
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
    public function incrementUsage(int $id): JsonResponse
    {
        $template = ReplyTemplate::find($id);

        if ($template) {
            $template->increment('usage_count');
        }

        return response()->json([
            'success' => true,
            'usage_count' => $template?->usage_count,
        ]);
    }
}
