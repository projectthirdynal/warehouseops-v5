<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Reference
            $table->string('ref')->unique();                        // INV-2026-00001
            $table->string('type')->default('standard');           // standard | credit_note | deposit | proforma
            $table->string('status')->default('DRAFT');            // DRAFT | VALIDATED | SENT | PARTIAL | PAID | OVERDUE | CANCELLED

            // Parties
            $table->foreignId('third_party_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_name');                         // Denormalized for history
            $table->string('client_email')->nullable();
            $table->string('client_phone')->nullable();
            $table->text('client_address')->nullable();

            // Linked records
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('quotation_id')->nullable(); // FK added later when quotations table exists

            // Dates
            $table->date('date_invoice');
            $table->date('date_due')->nullable();
            $table->date('date_sent')->nullable();

            // Payment terms
            $table->string('payment_terms')->nullable();            // COD | NET30 | NET60 | IMMEDIATE
            $table->string('currency')->default('PHP');

            // Financials
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);         // e.g. 12 for 12% VAT
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('shipping_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_due', 14, 2)->default(0);

            // Notes
            $table->text('notes')->nullable();                     // Internal notes
            $table->text('terms')->nullable();                     // Terms & conditions on invoice
            $table->text('footer')->nullable();                    // Footer text on PDF

            // Cancellation
            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'date_due']);
            $table->index(['third_party_id', 'status']);
            $table->index(['date_invoice']);
            $table->index(['type', 'status']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);               // Display order

            // Product link (optional)
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_ref')->nullable();             // Denormalized SKU
            $table->string('description');
            $table->string('unit')->nullable();                    // pcs, kg, hrs, etc.

            $table->decimal('qty', 10, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_ht', 12, 2)->default(0);       // Before tax
            $table->decimal('total_ttc', 12, 2)->default(0);      // After tax

            $table->timestamps();

            $table->index(['invoice_id', 'position']);
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_method')->default('cash');     // cash | bank_transfer | gcash | check | cod
            $table->string('reference_number')->nullable();        // Check no / transaction ref
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['invoice_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
