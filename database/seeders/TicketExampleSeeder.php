<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Seeder;

class TicketExampleSeeder extends Seeder
{
    public function run(): void
    {
        if (Ticket::count() > 0) {
            return;
        }

        $admin = User::where('role', 'admin')->first() ?? User::first();
        $supervisor = User::where('role', 'supervisor')->first() ?? $admin;
        $agent = User::where('role', 'agent')->first() ?? $admin;

        if (! $admin) {
            return;
        }

        $ticket = Ticket::create([
            'ticket_number' => 'TK-'.now()->format('ymd').'-0001',
            'subject' => 'Waybill WB-2026-00123 not delivered — customer complaint',
            'description' => "Customer Maria Santos reported that her order (WB-2026-00123) was marked as delivered on July 26 but she never received it. She called in very upset. The courier is J&T Express. Need to investigate and file a claim if necessary.\n\nCustomer contact: 0917-555-1234\nOrder amount: ₱2,450 COD",
            'status' => 'in_progress',
            'priority' => 'urgent',
            'category' => 'delivery',
            'created_by' => $agent->id,
            'assigned_to' => $supervisor->id,
            'related_waybill' => 'WB-2026-00123',
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => "Customer called again at 2:15 PM. She's very upset and threatening to file a complaint with DTI. We need to resolve this ASAP.",
            'is_internal' => false,
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $supervisor->id,
            'body' => "I've contacted J&T Express support. They're checking with the rider. Will update once I hear back. Targeting resolution by end of day.",
            'is_internal' => false,
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $supervisor->id,
            'body' => 'Internal note: J&T rider claims he left it with a neighbor at the same address. Checking CCTV from the barangay if available. May need to issue a replacement order if not found by 5 PM.',
            'is_internal' => true,
        ]);

        ActivityLog::log('ticket_created', $agent, 'ticket', $ticket->id, [
            'ticket_number' => $ticket->ticket_number,
            'priority' => 'urgent',
            'category' => 'delivery',
        ]);

        ActivityLog::log('ticket_assigned', $admin, 'ticket', $ticket->id, [
            'assigned_to' => $supervisor->name,
        ]);

        ActivityLog::log('ticket_status_changed', $supervisor, 'ticket', $ticket->id, [
            'from' => 'open',
            'to' => 'in_progress',
        ]);

        ActivityLog::log('ticket_comment_added', $agent, 'ticket', $ticket->id, [
            'comment_id' => 1,
            'is_internal' => false,
        ]);

        ActivityLog::log('ticket_comment_added', $supervisor, 'ticket', $ticket->id, [
            'comment_id' => 2,
            'is_internal' => false,
        ]);

        ActivityLog::log('ticket_comment_added', $supervisor, 'ticket', $ticket->id, [
            'comment_id' => 3,
            'is_internal' => true,
        ]);
    }
}
