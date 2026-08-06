<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('department', 80);
            $table->string('name');
            $table->enum('period_type', ['MONTHLY', 'QUARTERLY', 'YEARLY']);
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['DRAFT', 'ACTIVE', 'CLOSED'])->default('DRAFT');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['department', 'period_start']);
            $table->index('status');
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->string('category', 60);
            $table->enum('line_type', ['INCOME', 'EXPENSE']);
            $table->decimal('budgeted_amount', 14, 2)->default(0);
            $table->decimal('threshold_percent', 5, 2)->default(10.00);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['budget_id', 'category']);
        });

        Schema::create('budget_variance_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignId('budget_line_id')->constrained('budget_lines')->cascadeOnDelete();
            $table->decimal('budgeted_amount', 14, 2);
            $table->decimal('actual_amount', 14, 2);
            $table->decimal('variance_amount', 14, 2);
            $table->decimal('variance_percent', 5, 2);
            $table->enum('severity', ['WARNING', 'CRITICAL']);
            $table->text('message');
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['budget_id', 'is_resolved']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_variance_alerts');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
