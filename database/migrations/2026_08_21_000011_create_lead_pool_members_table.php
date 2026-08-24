<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_pool_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_pool_id')->constrained('lead_pools')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->string('status', 20)->default('PENDING');

            $table->timestamp('added_at')->useCurrent();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason')->nullable();

            $table->timestamps();

            $table->index(['lead_pool_id', 'status']);
            $table->index('lead_id');

            // Prevent a lead from belonging to multiple active pools simultaneously.
            // A partial unique index is created below (PostgreSQL-specific) that only
            // covers PENDING and ASSIGNED memberships, allowing historical records.
        });

        // PostgreSQL partial unique index: a lead can only be in one active (non-removed) membership.
        // This prevents duplicate pool membership while allowing historical records.
        DB::statement("
            CREATE UNIQUE INDEX lead_pool_members_lead_active_unique
            ON lead_pool_members (lead_id)
            WHERE status IN ('PENDING', 'ASSIGNED')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_pool_members');
    }
};
