<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cogs_daily_summaries')) {
            Schema::create('cogs_daily_summaries', function (Blueprint $table) {
                $table->id();
                $table->date('summary_date');
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

                $table->decimal('total_quantity', 14, 4)->default(0);
                $table->decimal('total_cost', 14, 4)->default(0);
                $table->decimal('avg_unit_cost', 12, 4)->default(0);
                $table->decimal('standard_cost', 12, 4)->default(0);
                $table->decimal('variance_amount', 14, 4)->default(0);
                $table->decimal('variance_pct', 8, 4)->default(0);
                $table->integer('entries_count')->default(0);
                $table->integer('orders_count')->default(0);

                $table->timestamps();

                $table->unique(['summary_date', 'product_id', 'variant_id']);
                $table->index(['summary_date']);
                $table->index(['product_id', 'summary_date']);
            });
        }

        if (! Schema::hasTable('cogs_variance_alerts')) {
            Schema::create('cogs_variance_alerts', function (Blueprint $table) {
                $table->id();
                $table->date('alert_date');
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

                $table->string('severity')->default('MEDIUM'); // LOW | MEDIUM | HIGH
                $table->string('alert_type')->default('COST_VARIANCE'); // COST_VARIANCE | MISSING_LOTS | NEGATIVE_VARIANCE
                $table->decimal('actual_cost', 14, 4)->default(0);
                $table->decimal('standard_cost', 12, 4)->default(0);
                $table->decimal('variance_amount', 14, 4)->default(0);
                $table->decimal('variance_pct', 8, 4)->default(0);
                $table->integer('affected_entries')->default(0);
                $table->text('message')->nullable();
                $table->boolean('resolved')->default(false);
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('resolution_note')->nullable();

                $table->timestamps();

                $table->index(['alert_date', 'severity']);
                $table->index(['product_id', 'alert_date']);
                $table->index(['resolved']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cogs_variance_alerts');
        Schema::dropIfExists('cogs_daily_summaries');
    }
};
