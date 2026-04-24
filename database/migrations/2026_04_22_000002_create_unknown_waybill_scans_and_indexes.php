<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unknown_waybill_scans', function (Blueprint $table) {
            $table->id();
            $table->string('waybill_no', 60);
            $table->foreignId('scanned_by')->constrained('users');
            $table->timestamp('scanned_at');
            $table->uuid('scan_session_id');
            $table->string('resolution_status')->default('PENDING'); // PENDING, RESOLVED, DISMISSED
            $table->foreignId('resolved_to_waybill_id')->nullable()->constrained('waybills')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('resolution_status');
            $table->index('waybill_no');
            $table->index('scanned_at');
            $table->index('scan_session_id');
        });

        // Performance indexes for claims and beyond-SLA queries
        Schema::table('claims', function (Blueprint $table) {
            $table->index('filed_at');
        });

        Schema::table('waybills', function (Blueprint $table) {
            $table->index('returned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unknown_waybill_scans');
    }
};
