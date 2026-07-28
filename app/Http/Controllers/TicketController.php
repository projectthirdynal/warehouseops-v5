<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            ],
            'activityLogs'    => $activityLogs,
            'comments'        => $comments,
            'assignableUsers' => $assignableUsers,
            'categories'      => $categories,
            'priorities'      => $priorities,
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

        return Inertia::render('Tickets/Settings', [
            'categories' => $categories,
            'priorities' => $priorities,
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
}
