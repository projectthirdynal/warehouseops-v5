<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketCannedResponse;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search'      => ['nullable', 'string', 'max:200'],
            'status'      => ['nullable', 'string', 'in:open,in_progress,waiting,resolved,closed'],
            'priority'    => ['nullable', 'string'],
            'category'    => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date'],
            'sla_status'  => ['nullable', 'string', 'in:on_track,warning,overdue,breached,met'],
            'sort_by'     => ['nullable', 'string', 'in:created_at,updated_at,due_at,priority,subject'],
            'sort_dir'    => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $query = Ticket::with(['createdBy:id,name', 'assignedTo:id,name']);

        // Search across subject, ticket_number, description
        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'ilike', "%{$search}%")
                  ->orWhere('ticket_number', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        // Status filter
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        // Priority filter
        if (!empty($validated['priority'])) {
            $query->where('priority', $validated['priority']);
        }

        // Category filter
        if (!empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        // Assignee filter
        if (!empty($validated['assigned_to'])) {
            $query->where('assigned_to', $validated['assigned_to']);
        }

        // Date range filter
        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        // SLA status filter (post-filter in PHP since it's computed)
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        $tickets = $query->limit(100)->get()->map(fn ($t) => [
            'id'              => $t->id,
            'ticket_number'   => $t->ticket_number,
            'subject'         => $t->subject,
            'description'     => $t->description,
            'status'          => $t->status,
            'priority'        => $t->priority,
            'category'        => $t->category,
            'created_by'      => $t->createdBy,
            'assigned_to'     => $t->assignedTo,
            'related_waybill' => $t->related_waybill,
            'related_lead'    => $t->related_lead,
            'created_at'      => $t->created_at,
            'updated_at'      => $t->updated_at,
            'messages_count'  => $t->comments()->count(),
            'due_at'          => $t->due_at?->toIso8601String(),
            'sla_status'      => $t->slaStatus(),
            'sla_remaining'   => $t->timeRemaining(),
        ]);

        // SLA status post-filter
        if (!empty($validated['sla_status'])) {
            $slaFilter = $validated['sla_status'];
            $tickets = $tickets->filter(fn ($t) => $t['sla_status'] === $slaFilter);
        }

        $stats = [
            'total'          => Ticket::count(),
            'open'           => Ticket::where('status', 'open')->count(),
            'in_progress'    => Ticket::where('status', 'in_progress')->count(),
            'resolved_today' => Ticket::where('status', 'resolved')->whereDate('updated_at', today())->count(),
            'overdue'        => Ticket::whereNotNull('due_at')->where('due_at', '<', now())->whereNotIn('status', ['resolved', 'closed'])->count(),
        ];

        $categories = TicketCategory::orderBy('sort_order')->get(['id', 'name', 'slug', 'color', 'is_active']);
        $priorities = TicketPriority::orderBy('sort_order')->get(['id', 'name', 'slug', 'color', 'level', 'is_active']);
        $assignableUsers = User::whereIn('role', ['superadmin', 'admin', 'supervisor', 'agent'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Tickets/Index', [
            'tickets'        => $tickets->values(),
            'stats'          => $stats,
            'categories'     => $categories,
            'priorities'     => $priorities,
            'assignableUsers' => $assignableUsers,
            'currentUserId'  => $request->user()->id,
            'filters'        => $request->only(['search', 'status', 'priority', 'category', 'assigned_to', 'date_from', 'date_to', 'sla_status', 'sort_by', 'sort_dir']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject'         => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string', 'max:5000'],
            'priority'        => ['required', 'string', 'max:50'],
            'category'        => ['required', 'string', 'max:100'],
            'related_waybill' => ['nullable', 'string', 'max:100'],
        ]);

        $ticket = Ticket::create([
            'ticket_number'   => Ticket::generateTicketNumber(),
            'subject'         => $validated['subject'],
            'description'     => $validated['description'] ?? null,
            'priority'        => $validated['priority'],
            'category'        => $validated['category'],
            'related_waybill' => $validated['related_waybill'] ?? null,
            'status'          => 'open',
            'created_by'      => $request->user()->id,
            'due_at'          => Ticket::calculateDueAt($validated['priority']),
        ]);

        // Notify assignable users about new ticket
        $creator = User::find($request->user()->id);
        $assignableUsers = User::whereIn('role', ['superadmin', 'admin', 'supervisor', 'agent'])
            ->where('is_active', true)
            ->where('id', '!=', $creator->id)
            ->get();

        \Illuminate\Support\Facades\Notification::send(
            $assignableUsers,
            new TicketCreatedNotification($ticket, $creator->name),
        );

        return back()->with('success', "Ticket {$ticket->ticket_number} created.");
    }

    public function show(Request $request, Ticket $ticket)
    {
        $ticket->load(['createdBy:id,name,email', 'assignedTo:id,name,email']);
        $ticket->loadCount('comments');

        $comments = $ticket->comments()
            ->with('user:id,name')
            ->get()
            ->map(fn ($c) => [
                'id'           => $c->id,
                'body'         => $c->body,
                'is_internal'  => $c->is_internal,
                'user'         => $c->user,
                'created_at'   => $c->created_at,
            ]);

        $activityLogs = ActivityLog::where('entity_type', 'ticket')
            ->where('entity_id', $ticket->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($log) => [
                'id'         => $log->id,
                'action'     => $log->action,
                'user'       => $log->user,
                'metadata'   => $log->metadata,
                'created_at' => $log->created_at,
            ]);

        $assignableUsers = User::whereIn('role', ['superadmin', 'admin', 'supervisor', 'agent'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $categories = TicketCategory::orderBy('sort_order')->get(['id', 'name', 'slug', 'color', 'is_active']);
        $priorities = TicketPriority::orderBy('sort_order')->get(['id', 'name', 'slug', 'color', 'level', 'is_active']);
        $cannedResponses = TicketCannedResponse::where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title', 'body', 'category']);

        return Inertia::render('Tickets/Show', [
            'ticket'       => [
                'id'              => $ticket->id,
                'ticket_number'   => $ticket->ticket_number,
                'subject'         => $ticket->subject,
                'description'     => $ticket->description,
                'status'          => $ticket->status,
                'priority'        => $ticket->priority,
                'category'        => $ticket->category,
                'created_by'      => $ticket->createdBy,
                'assigned_to'     => $ticket->assignedTo,
                'related_waybill' => $ticket->related_waybill,
                'related_lead'    => $ticket->related_lead,
                'created_at'      => $ticket->created_at,
                'updated_at'      => $ticket->updated_at,
                'due_at'          => $ticket->due_at?->toIso8601String(),
                'resolved_at'     => $ticket->resolved_at?->toIso8601String(),
                'sla_status'      => $ticket->slaStatus(),
                'sla_remaining'   => $ticket->timeRemaining(),
                'satisfaction_rating'       => $ticket->satisfaction_rating,
                'satisfaction_comment'      => $ticket->satisfaction_comment,
                'satisfaction_submitted_at' => $ticket->satisfaction_submitted_at?->toIso8601String(),
            ],
            'activityLogs'    => $activityLogs,
            'comments'        => $comments,
            'assignableUsers' => $assignableUsers,
            'categories'      => $categories,
            'priorities'      => $priorities,
            'cannedResponses' => $cannedResponses,
            'currentUserId'   => $request->user()->id,
        ]);
    }

    public function storeComment(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'body'        => ['required', 'string', 'max:5000'],
            'is_internal' => ['boolean'],
        ]);

        $comment = TicketComment::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $request->user()->id,
            'body'        => $validated['body'],
            'is_internal' => $validated['is_internal'] ?? false,
        ]);

        ActivityLog::log('ticket_comment_added', $request->user(), 'ticket', $ticket->id, [
            'comment_id' => $comment->id,
            'is_internal' => $comment->is_internal,
        ]);

        // Notify ticket creator and assignee about the reply (skip internal notes for non-admin)
        if (!$comment->is_internal) {
            $recipients = collect();
            if ($ticket->created_by && $ticket->created_by !== $request->user()->id) {
                $recipients->push(User::find($ticket->created_by));
            }
            if ($ticket->assigned_to && $ticket->assigned_to !== $request->user()->id) {
                $recipients->push(User::find($ticket->assigned_to));
            }
            $recipients = $recipients->filter()->unique('id');
            \Illuminate\Support\Facades\Notification::send(
                $recipients,
                new TicketRepliedNotification($ticket, $request->user()->name, $comment->body, false),
            );
        }

        return back()->with('success', 'Comment added.');
    }

    public function destroyComment(Request $request, Ticket $ticket, TicketComment $comment)
    {
        if ($comment->ticket_id !== $ticket->id) {
            return back()->withErrors(['comment' => 'Comment does not belong to this ticket.']);
        }

        $isInternal = $comment->is_internal;
        $comment->delete();

        ActivityLog::log('ticket_comment_deleted', $request->user(), 'ticket', $ticket->id, [
            'comment_id' => $comment->id,
            'is_internal' => $isInternal,
        ]);

        return back()->with('success', $isInternal ? 'Internal note deleted.' : 'Comment deleted.');
    }

    public function submitSurvey(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'satisfaction_rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'satisfaction_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $ticket->update([
            'satisfaction_rating'       => $validated['satisfaction_rating'],
            'satisfaction_comment'      => $validated['satisfaction_comment'] ?? null,
            'satisfaction_submitted_at' => now(),
        ]);

        ActivityLog::log('ticket_satisfaction_survey', $request->user(), 'ticket', $ticket->id, [
            'rating' => $validated['satisfaction_rating'],
        ]);

        return back()->with('success', 'Thank you for your feedback!');
    }

    public function updateStatus(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:open,in_progress,waiting,resolved,closed'],
        ]);

        $oldStatus = $ticket->status;
        $newStatus = $validated['status'];

        $allowedTransitions = [
            'open'         => ['in_progress', 'waiting', 'resolved', 'closed'],
            'in_progress'  => ['waiting', 'resolved', 'closed', 'open'],
            'waiting'      => ['in_progress', 'resolved', 'closed', 'open'],
            'resolved'     => ['closed', 'in_progress', 'open'],
            'closed'       => ['in_progress', 'open'],
        ];

        if (!in_array($newStatus, $allowedTransitions[$oldStatus] ?? [])) {
            return back()->withErrors(['status' => "Cannot transition from {$oldStatus} to {$newStatus}."]);
        }

        $updateData = ['status' => $newStatus];
        if (in_array($newStatus, ['resolved', 'closed']) && !$ticket->resolved_at) {
            $updateData['resolved_at'] = now();
        }
        if (!in_array($newStatus, ['resolved', 'closed'])) {
            $updateData['resolved_at'] = null;
        }
        $ticket->update($updateData);

        ActivityLog::log('ticket_status_changed', $request->user(), 'ticket', $ticket->id, [
            'from' => $oldStatus,
            'to'   => $newStatus,
        ]);

        // Notify ticket creator and assignee about status change
        $recipients = collect();
        if ($ticket->created_by && $ticket->created_by !== $request->user()->id) {
            $recipients->push(User::find($ticket->created_by));
        }
        if ($ticket->assigned_to && $ticket->assigned_to !== $request->user()->id) {
            $recipients->push(User::find($ticket->assigned_to));
        }
        $recipients = $recipients->filter()->unique('id');
        \Illuminate\Support\Facades\Notification::send(
            $recipients,
            new TicketStatusChangedNotification($ticket, $oldStatus, $newStatus, $request->user()->name),
        );

        return back()->with('success', "Ticket status updated to {$newStatus}.");
    }

    public function assign(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'assigned_to' => ['required', 'exists:users,id'],
        ]);

        $previousAssignee = $ticket->assigned_to;
        $newAssignee = User::find($validated['assigned_to']);

        $ticket->update(['assigned_to' => $validated['assigned_to']]);

        $previousAssigneeName = $previousAssignee ? User::find($previousAssignee)?->name : null;

        ActivityLog::log('ticket_assigned', $request->user(), 'ticket', $ticket->id, [
            'from' => $previousAssignee ? ['id' => $previousAssignee, 'name' => $previousAssigneeName] : null,
            'to'   => ['id' => $newAssignee->id, 'name' => $newAssignee->name],
        ]);

        // Notify the new assignee
        $newAssignee->notify(new TicketAssignedNotification(
            $ticket,
            $previousAssigneeName,
            $newAssignee->name,
            $request->user()->name,
        ));

        // Also notify ticket creator (if not the assigner)
        if ($ticket->created_by && $ticket->created_by !== $request->user()->id) {
            $creator = User::find($ticket->created_by);
            if ($creator) {
                $creator->notify(new TicketAssignedNotification(
                    $ticket,
                    $previousAssigneeName,
                    $newAssignee->name,
                    $request->user()->name,
                ));
            }
        }

        return back()->with('success', "Ticket assigned to {$newAssignee->name}.");
    }

    public function settings()
    {
        $categories = TicketCategory::orderBy('sort_order')->get();
        $priorities = TicketPriority::orderBy('sort_order')->get();
        $cannedResponses = TicketCannedResponse::orderByDesc('created_at')->get();

        return Inertia::render('Tickets/Settings', [
            'categories'       => $categories,
            'priorities'       => $priorities,
            'cannedResponses'  => $cannedResponses,
        ]);
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'color'      => ['required', 'string', 'max:20'],
            'is_active'  => ['boolean'],
            'sort_order' => ['integer'],
        ]);

        TicketCategory::create([
            'name'       => $validated['name'],
            'slug'       => Str::slug($validated['name']),
            'color'      => $validated['color'],
            'is_active'  => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, TicketCategory $category)
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'color'      => ['required', 'string', 'max:20'],
            'is_active'  => ['boolean'],
            'sort_order' => ['integer'],
        ]);

        $category->update([
            'name'       => $validated['name'],
            'slug'       => Str::slug($validated['name']),
            'color'      => $validated['color'],
            'is_active'  => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(TicketCategory $category)
    {
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    public function storePriority(Request $request)
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'color'      => ['required', 'string', 'max:20'],
            'level'      => ['required', 'integer'],
            'is_active'  => ['boolean'],
            'sort_order' => ['integer'],
        ]);

        TicketPriority::create([
            'name'       => $validated['name'],
            'slug'       => Str::slug($validated['name']),
            'color'      => $validated['color'],
            'level'      => $validated['level'],
            'is_active'  => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return back()->with('success', 'Priority created.');
    }

    public function updatePriority(Request $request, TicketPriority $priority)
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'color'      => ['required', 'string', 'max:20'],
            'level'      => ['required', 'integer'],
            'is_active'  => ['boolean'],
            'sort_order' => ['integer'],
        ]);

        $priority->update([
            'name'       => $validated['name'],
            'slug'       => Str::slug($validated['name']),
            'color'      => $validated['color'],
            'level'      => $validated['level'],
            'is_active'  => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return back()->with('success', 'Priority updated.');
    }

    public function destroyPriority(TicketPriority $priority)
    {
        $priority->delete();

        return back()->with('success', 'Priority deleted.');
    }

    // ---- Canned Responses ----

    public function storeCannedResponse(Request $request)
    {
        $validated = $request->validate([
            'title'    => ['required', 'string', 'max:200'],
            'body'     => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        TicketCannedResponse::create([
            'title'    => $validated['title'],
            'body'     => $validated['body'],
            'category' => $validated['category'] ?? 'general',
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Canned response created.');
    }

    public function updateCannedResponse(Request $request, TicketCannedResponse $cannedResponse)
    {
        $validated = $request->validate([
            'title'    => ['required', 'string', 'max:200'],
            'body'     => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $cannedResponse->update([
            'title'    => $validated['title'],
            'body'     => $validated['body'],
            'category' => $validated['category'] ?? 'general',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', 'Canned response updated.');
    }

    public function destroyCannedResponse(TicketCannedResponse $cannedResponse)
    {
        $cannedResponse->delete();

        return back()->with('success', 'Canned response deleted.');
    }

    public function useCannedResponse(Request $request, TicketCannedResponse $cannedResponse)
    {
        $cannedResponse->increment('usage_count');

        return response()->json([
            'body' => $cannedResponse->body,
        ]);
    }

    public function bulkAssign(Request $request)
    {
        $validated = $request->validate([
            'ticket_ids'   => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
            'assigned_to'  => ['required', 'exists:users,id'],
        ]);

        $tickets = Ticket::whereIn('id', $validated['ticket_ids'])->get();
        $assignee = User::find($validated['assigned_to']);
        $count = 0;

        foreach ($tickets as $ticket) {
            if ($ticket->assigned_to !== $assignee->id) {
                $previousAssignee = $ticket->assigned_to;
                $ticket->update(['assigned_to' => $assignee->id]);

                ActivityLog::log('ticket_assigned', $request->user(), 'ticket', $ticket->id, [
                    'from' => $previousAssignee ? ['id' => $previousAssignee, 'name' => $previousAssignee] : null,
                    'to'   => ['id' => $assignee->id, 'name' => $assignee->name],
                    'bulk' => true,
                ]);
                $count++;
            }
        }

        return back()->with('success', "{$count} ticket(s) assigned to {$assignee->name}.");
    }

    public function bulkClose(Request $request)
    {
        $validated = $request->validate([
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
        ]);

        $tickets = Ticket::whereIn('id', $validated['ticket_ids'])
            ->whereNotIn('status', ['closed'])
            ->get();
        $count = 0;

        foreach ($tickets as $ticket) {
            $oldStatus = $ticket->status;
            $ticket->update([
                'status'      => 'closed',
                'resolved_at' => $ticket->resolved_at ?? now(),
            ]);

            ActivityLog::log('ticket_status_changed', $request->user(), 'ticket', $ticket->id, [
                'from' => $oldStatus,
                'to'   => 'closed',
                'bulk' => true,
            ]);
            $count++;
        }

        return back()->with('success', "{$count} ticket(s) closed.");
    }

    public function bulkPriorityChange(Request $request)
    {
        $validated = $request->validate([
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
            'priority'   => ['required', 'string', 'max:50'],
        ]);

        $tickets = Ticket::whereIn('id', $validated['ticket_ids'])->get();
        $count = 0;

        foreach ($tickets as $ticket) {
            if ($ticket->priority !== $validated['priority']) {
                $oldPriority = $ticket->priority;
                $ticket->update([
                    'priority' => $validated['priority'],
                    'due_at'    => Ticket::calculateDueAt($validated['priority'], $ticket->created_at),
                ]);

                ActivityLog::log('ticket_priority_changed', $request->user(), 'ticket', $ticket->id, [
                    'from' => $oldPriority,
                    'to'   => $validated['priority'],
                    'bulk' => true,
                ]);
                $count++;
            }
        }

        return back()->with('success', "{$count} ticket(s) priority updated.");
    }

    public function analytics()
    {
        // ── Overview stats ──
        $totalTickets    = Ticket::count();
        $openTickets     = Ticket::whereIn('status', ['open', 'in_progress', 'waiting'])->count();
        $resolvedTickets = Ticket::whereIn('status', ['resolved', 'closed'])->count();
        $overdueCount    = Ticket::whereNotNull('due_at')->whereIn('status', ['open', 'in_progress', 'waiting'])->where('due_at', '<', now())->count();

        // ── Resolution time (avg hours for resolved/closed tickets with resolved_at) ──
        $resolutionStats = Ticket::whereIn('status', ['resolved', 'closed'])
            ->whereNotNull('resolved_at')
            ->whereNotNull('created_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')
            ->selectRaw('MIN(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as min_hours')
            ->selectRaw('MAX(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as max_hours')
            ->first();

        $avgResolutionHours = $resolutionStats?->avg_hours ? round((float) $resolutionStats->avg_hours, 1) : null;
        $minResolutionHours = $resolutionStats?->min_hours ? round((float) $resolutionStats->min_hours, 1) : null;
        $maxResolutionHours = $resolutionStats?->max_hours ? round((float) $resolutionStats->max_hours, 1) : null;

        // ── Status breakdown ──
        $statusBreakdown = Ticket::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // ── Priority breakdown ──
        $priorityBreakdown = Ticket::select('priority', DB::raw('count(*) as count'))
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        // ── Category breakdown ──
        $categoryBreakdown = Ticket::select('category', DB::raw('count(*) as count'))
            ->groupBy('category')
            ->orderByDesc('count')
            ->pluck('count', 'category')
            ->toArray();

        // ── SLA compliance ──
        $slaMet    = Ticket::whereIn('status', ['resolved', 'closed'])->whereNotNull('due_at')->whereNotNull('resolved_at')->where('resolved_at', '<=', DB::raw('due_at'))->count();
        $slaBreached = Ticket::whereIn('status', ['resolved', 'closed'])->whereNotNull('due_at')->whereNotNull('resolved_at')->where('resolved_at', '>', DB::raw('due_at'))->count();
        $slaPending  = Ticket::whereIn('status', ['open', 'in_progress', 'waiting'])->whereNotNull('due_at')->where('due_at', '<', now())->count();
        $slaOnTrack  = Ticket::whereIn('status', ['open', 'in_progress', 'waiting'])->whereNotNull('due_at')->where('due_at', '>=', now())->count();

        $totalSla = $slaMet + $slaBreached;
        $slaComplianceRate = $totalSla > 0 ? round(($slaMet / $totalSla) * 100, 1) : null;

        // ── Satisfaction stats ──
        $satisfactionRated = Ticket::whereNotNull('satisfaction_rating')->count();
        $avgSatisfaction   = Ticket::whereNotNull('satisfaction_rating')->avg('satisfaction_rating');
        $satisfactionDist  = Ticket::whereNotNull('satisfaction_rating')
            ->select('satisfaction_rating', DB::raw('count(*) as count'))
            ->groupBy('satisfaction_rating')
            ->pluck('count', 'satisfaction_rating')
            ->toArray();

        // ── Tickets created over last 30 days (daily) ──
        $trend = Ticket::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'total' => (int) $row->total]);

        // ── Resolution trend (last 30 days, resolved_at) ──
        $resolutionTrend = Ticket::whereIn('status', ['resolved', 'closed'])
            ->where('resolved_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(resolved_at) as date, COUNT(*) as resolved')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'resolved' => (int) $row->resolved]);

        // Merge trends on date
        $trendDates = $trend->pluck('date')->merge($resolutionTrend->pluck('date'))->unique()->sort()->values();
        $trendMerged = $trendDates->map(function ($date) use ($trend, $resolutionTrend) {
            $created  = $trend->firstWhere('date', $date);
            $resolved = $resolutionTrend->firstWhere('date', $date);

            return [
                'date'     => $date,
                'created'  => $created['total'] ?? 0,
                'resolved' => $resolved['resolved'] ?? 0,
            ];
        });

        // ── Top assignees ──
        $topAssignees = Ticket::whereNotNull('assigned_to')
            ->select('assigned_to', DB::raw('count(*) as ticket_count'))
            ->with('assignedTo:id,name')
            ->groupBy('assigned_to')
            ->orderByDesc('ticket_count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'name'        => $row->assignedTo?->name ?? 'Unknown',
                'ticket_count' => (int) $row->ticket_count,
            ]);

        return Inertia::render('Tickets/Analytics', [
            'overview' => [
                'total'     => $totalTickets,
                'open'      => $openTickets,
                'resolved'  => $resolvedTickets,
                'overdue'   => $overdueCount,
            ],
            'resolutionTime' => [
                'avg_hours' => $avgResolutionHours,
                'min_hours' => $minResolutionHours,
                'max_hours' => $maxResolutionHours,
            ],
            'statusBreakdown'    => $statusBreakdown,
            'priorityBreakdown'  => $priorityBreakdown,
            'categoryBreakdown'  => $categoryBreakdown,
            'sla' => [
                'met'             => $slaMet,
                'breached'        => $slaBreached,
                'pending_overdue' => $slaPending,
                'on_track'        => $slaOnTrack,
                'compliance_rate' => $slaComplianceRate,
            ],
            'satisfaction' => [
                'rated'   => $satisfactionRated,
                'average' => $avgSatisfaction ? round((float) $avgSatisfaction, 2) : null,
                'distribution' => $satisfactionDist,
            ],
            'trend'          => $trendMerged,
            'topAssignees'   => $topAssignees,
        ]);
    }

    public function exportCsv(Request $request)
    {
        $validated = $request->validate([
            'search'      => ['nullable', 'string', 'max:200'],
            'status'      => ['nullable', 'string', 'in:open,in_progress,waiting,resolved,closed'],
            'priority'    => ['nullable', 'string'],
            'category'    => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date'],
            'sla_status'  => ['nullable', 'string', 'in:on_track,warning,overdue,breached,met'],
            'sort_by'     => ['nullable', 'string', 'in:created_at,updated_at,due_at,priority,subject'],
            'sort_dir'    => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $query = Ticket::with(['createdBy:id,name', 'assignedTo:id,name']);

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'ilike', "%{$search}%")
                  ->orWhere('ticket_number', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['priority'])) {
            $query->where('priority', $validated['priority']);
        }
        if (!empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }
        if (!empty($validated['assigned_to'])) {
            $query->where('assigned_to', $validated['assigned_to']);
        }
        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        $tickets = $query->limit(500)->get();

        // SLA status post-filter
        if (!empty($validated['sla_status'])) {
            $slaFilter = $validated['sla_status'];
            $tickets = $tickets->filter(fn ($t) => $t->slaStatus() === $slaFilter);
        }

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="tickets-export-' . now()->format('Y-m-d') . '.csv"',
        ];

        $columns = [
            'Ticket #',
            'Subject',
            'Status',
            'Priority',
            'Category',
            'Created By',
            'Assigned To',
            'Related Waybill',
            'Related Lead',
            'SLA Status',
            'Due At',
            'Resolved At',
            'Satisfaction Rating',
            'Satisfaction Comment',
            'Created At',
            'Updated At',
        ];

        $callback = function () use ($tickets, $columns) {
            $fh = fopen('php://output', 'w');
            fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($fh, $columns);

            foreach ($tickets as $t) {
                fputcsv($fh, [
                    $t->ticket_number,
                    $t->subject,
                    $t->status,
                    $t->priority,
                    $t->category,
                    $t->createdBy?->name ?? '',
                    $t->assignedTo?->name ?? 'Unassigned',
                    $t->related_waybill ?? '',
                    $t->related_lead ?? '',
                    $t->slaStatus(),
                    $t->due_at?->format('Y-m-d H:i') ?? '',
                    $t->resolved_at?->format('Y-m-d H:i') ?? '',
                    $t->satisfaction_rating !== null ? (string) $t->satisfaction_rating . '/5' : '',
                    $t->satisfaction_comment ?? '',
                    $t->created_at?->format('Y-m-d H:i') ?? '',
                    $t->updated_at?->format('Y-m-d H:i') ?? '',
                ]);
            }

            fclose($fh);
        };

        return response()->stream($callback, 200, $headers);
    }
}
