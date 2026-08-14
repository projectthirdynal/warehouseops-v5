<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('period_type'); // DAILY, WEEKLY, MONTHLY, MANUAL
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('DRAFT'); // DRAFT, PENDING_APPROVAL, APPROVED, PAID, REJECTED
            $table->unsignedInteger('commission_count')->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'period_start']);
            $table->index(['period_type', 'status']);
        });

        Schema::table('agent_commissions', function (Blueprint $table) {
            $table->foreignId('commission_run_id')->nullable()->after('waybill_id')
                ->constrained('commission_runs')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('cancelled_at');
            $table->text('rejection_reason')->nullable()->after('rejected_at');

            $table->index(['commission_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_commissions', function (Blueprint $table) {
            $table->dropForeign(['commission_run_id']);
            $table->dropIndex(['commission_run_id', 'status']);
            $table->dropColumn(['commission_run_id', 'rejected_at', 'rejection_reason']);
        });

        Schema::dropIfExists('commission_runs');
    }
};
