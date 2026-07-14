<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Map old statuses to new canonical statuses
        // open (no agent) -> new
        // open (has agent) -> assigned
        // pending_details, for_confirmation -> awaiting_customer
        // confirmed, converted -> resolved
        // closed -> archived

        DB::table('conversations')
            ->where('status', 'open')
            ->whereNull('assigned_agent_id')
            ->update(['status' => 'new']);

        DB::table('conversations')
            ->where('status', 'open')
            ->whereNotNull('assigned_agent_id')
            ->update(['status' => 'assigned']);

        DB::table('conversations')
            ->whereIn('status', ['pending_details', 'for_confirmation'])
            ->update(['status' => 'awaiting_customer']);

        DB::table('conversations')
            ->whereIn('status', ['confirmed', 'converted'])
            ->update(['status' => 'resolved']);

        DB::table('conversations')
            ->where('status', 'closed')
            ->update(['status' => 'archived']);
    }

    public function down(): void
    {
        // Reverse mapping is lossy — we can't perfectly reconstruct old statuses.
        // Map back to closest equivalents.
        DB::table('conversations')->where('status', 'new')->update(['status' => 'open']);
        DB::table('conversations')->where('status', 'assigned')->update(['status' => 'open']);
        DB::table('conversations')->where('status', 'awaiting_customer')->update(['status' => 'pending_details']);
        // resolved stays as-is (was 'resolved' in some flows already)
        DB::table('conversations')->where('status', 'archived')->update(['status' => 'closed']);
    }
};
