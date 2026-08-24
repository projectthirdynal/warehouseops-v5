<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_sheet_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('courier', 20)->default('jnt');
            $table->text('sheet_url');
            $table->string('sheet_gid', 20)->default('0');
            $table->boolean('is_active')->default(true);
            $table->unsignedinteger('sync_interval_minutes')->default(15);
            $table->enum('last_sync_status', ['pending', 'processing', 'completed', 'completed_with_errors', 'failed'])->default('pending');
            $table->text('last_sync_message')->nullable();
            $table->unsignedinteger('last_sync_rows')->default(0);
            $table->unsignedinteger('last_sync_inserted')->default(0);
            $table->unsignedinteger('last_sync_updated')->default(0);
            $table->unsignedinteger('last_sync_skipped')->default(0);
            $table->unsignedinteger('last_sync_errors')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('is_active');
            $table->index('courier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_sheet_syncs');
    }
};
