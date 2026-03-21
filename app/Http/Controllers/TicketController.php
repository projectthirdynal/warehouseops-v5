<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function index()
    {
        // For now, return empty data - tickets table can be added later
        $tickets = [];

        $stats = [
            'total' => 0,
            'open' => 0,
            'in_progress' => 0,
            'resolved_today' => 0,
        ];

        return Inertia::render('Tickets/Index', [
            'tickets' => $tickets,
            'stats' => $stats,
        ]);
    }
}
