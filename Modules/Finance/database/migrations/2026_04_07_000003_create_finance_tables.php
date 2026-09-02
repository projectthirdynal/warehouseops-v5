<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Commission rules (per-product or default)
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rate_type')->default('PERCENTAGE'); // PERCENTAGE or FIXED
            $table->decimal('rate_value', 8, 2)->default(0);
            $table->decimal('min_sale_amount', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        // Agent commissions
        Schema::create('agent_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('waybill_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('sale_amount', 10, 2)->default(0);
            $table->decimal('commission_rate', 8, 4)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->string('status')->default('PENDING'); // PENDING, APPROVED, PAID, CANCELLED
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index(['order_id']);
            $table->index(['status']);
        });

        // COD settlements from couriers
        Schema::create('cod_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('courier_code');
            $table->string('reference_number')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_cod_collected', 12, 2)->default(0);
            $table->decimal('courier_fee', 10, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->integer('order_count')->default(0);
            $table->string('status')->default('PENDING'); // PENDING, RECEIVED, RECONCILED
            $table->timestamp('received_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['courier_code', 'status']);
            $table->index(['period_start', 'period_end']);
        });

        // Financial transactions (general ledger)
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // REVENUE, COD_COLLECTION, SHIPPING_COST, COMMISSION, REFUND, ADJUSTMENT
            $table->decimal('amount', 12, 2); // signed: positive=income, negative=expense
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('transaction_date');
            $table->timestamps();

            $table->index(['type', 'transaction_date']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('cod_settlements');
        Schema::dropIfExists('agent_commissions');
        Schema::dropIfExists('commission_rules');
    }
};
