<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('third_parties', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('ref')->unique()->nullable();           // TP-2026-00001
            $table->string('name');
            $table->string('alias')->nullable();
            $table->string('type')->default('customer');           // customer | supplier | prospect | partner | both

            // Contact info
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_alt')->nullable();
            $table->string('website')->nullable();

            // Business info
            $table->string('tax_id')->nullable();                  // TIN / VAT number
            $table->string('industry')->nullable();
            $table->string('currency')->default('PHP');
            $table->string('payment_terms')->nullable();           // NET30, COD, IMMEDIATE
            $table->decimal('credit_limit', 12, 2)->default(0);

            // Address (primary — full addresses in addresses table)
            $table->string('address_line1')->nullable();
            $table->string('city')->nullable();
            $table->string('state_province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Philippines');

            // CRM fields
            $table->string('status')->default('active');           // active | inactive | blacklisted | prospect
            $table->string('source')->nullable();                  // WAYBILL | LEAD | MANUAL | IMPORT | SHOP
            $table->string('assigned_to')->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();

            // Risk / compliance (migrated from customers)
            $table->string('risk_level')->default('LOW');          // LOW | MEDIUM | HIGH | BLACKLISTED
            $table->boolean('is_blacklisted')->default(false);
            $table->text('blacklist_reason')->nullable();
            $table->timestamp('blacklisted_at')->nullable();

            // Stats (denormalized for perf)
            $table->integer('total_orders')->default(0);
            $table->integer('successful_orders')->default(0);
            $table->integer('returned_orders')->default(0);
            $table->decimal('total_revenue', 14, 2)->default(0);
            $table->decimal('success_rate', 5, 2)->default(0);
            $table->timestamp('last_order_date')->nullable();

            // Link back to legacy customer (for migration continuity)
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['phone']);
            $table->index(['email']);
            $table->index(['risk_level']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_parties');
    }
};
