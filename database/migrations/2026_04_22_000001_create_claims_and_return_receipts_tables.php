<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_number', 30)->unique();
            $table->foreignId('waybill_id')->constrained()->cascadeOnDelete();
            $table->string('type');   // LOST, DAMAGED, BEYOND_SLA
            $table->string('status')->default('DRAFT');
            $table->text('description')->nullable();
            $table->decimal('claim_amount', 10, 2)->default(0);
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->string('jnt_reference_number')->nullable();
            $table->foreignId('filed_by')->constrained('users');
            $table->timestamp('filed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('type');
            $table->index(['status', 'type']);
            $table->index('filed_by');
        });

        Schema::create('return_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waybill_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('scanned_by')->constrained('users');
            $table->timestamp('scanned_at');
            $table->string('condition'); // GOOD, DAMAGED
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('scanned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_receipts');
        Schema::dropIfExists('claims');
    }
};
