<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->string('gateway'); // GCASH, BANK_TRANSFER, MAYA, CARD
            $table->string('status')->default('PENDING'); // PENDING, VERIFIED, RECONCILED, FAILED, REFUNDED
            $table->string('transaction_type')->default('INCOMING'); // INCOMING, OUTGOING
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cod_settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sender_name')->nullable();
            $table->string('sender_account')->nullable();
            $table->string('sender_phone')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_account')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('transaction_date')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reconciliation_ref')->nullable();
            $table->json('gateway_response')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'status']);
            $table->index(['status', 'transaction_date']);
            $table->index(['invoice_id']);
            $table->index(['order_id']);
            $table->index(['cod_settlement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
