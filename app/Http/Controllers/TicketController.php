<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function index()
    {
        $tickets = Ticket::with(['createdBy:id,name', 'assignedTo:id,name'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($t) => [
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
                'messages_count'  => 0,
            ]);

        $stats = [
            'total'          => Ticket::count(),
            'open'           => Ticket::where('status', 'open')->count(),
            'in_progress'    => Ticket::where('status', 'in_progress')->count(),
            'resolved_today' => Ticket::where('status', 'resolved')->whereDate('updated_at', today())->count(),
        ];

        return Inertia::render('Tickets/Index', [
            'tickets'    => $tickets,
            'stats'      => $stats,
            'categories' => ['general', 'waybill', 'delivery', 'product', 'billing', 'technical', 'other'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject'         => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string', 'max:5000'],
            'priority'        => ['required', 'in:low,medium,high,urgent'],
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
        ]);

        return back()->with('success', "Ticket {$ticket->ticket_number} created.");
    }
}
