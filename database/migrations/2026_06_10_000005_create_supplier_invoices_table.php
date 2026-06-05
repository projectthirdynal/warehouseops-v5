<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();

            $table->string('ref')->unique();                     // SINV-2026-00001
            $table->string('status')->default('DRAFT');          // DRAFT | VALIDATED | SENT | PARTIAL | PAID | OVERDUE | CANCELLED

            $table->foreignId('third_party_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name');
            $table->string('supplier_email')->nullable();
            $table->string('supplier_phone')->nullable();
            $table->text('supplier_address')->nullable();

            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete(); // Link to customer invoice if this is credit

            $table->date('date_invoice');
            $table->date('date_due')->nullable();
            $table->date('date_receipt')->nullable();

            $table->string('payment_terms')->nullable();         // NET30 | NET60 | IMMEDIATE
            $table->string('currency')->default('PHP');

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('shipping_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_due', 14, 2)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'date_due']);
            $table->index(['third_party_id', 'status']);
            $table->index(['date_invoice']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
    }
};
