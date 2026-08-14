<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add po_id to supplier_invoices
        if (! Schema::hasColumn('supplier_invoices', 'po_id')) {
            Schema::table('supplier_invoices', function (Blueprint $table) {
                $table->foreignId('po_id')->nullable()->after('invoice_id')
                    ->constrained('purchase_orders')->nullOnDelete();
                $table->index(['po_id', 'status']);
            });
        }

        // 2. Supplier invoice line items (for line-level three-way match)
        if (! Schema::hasTable('supplier_invoice_items')) {
            Schema::create('supplier_invoice_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
                $table->foreignId('po_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->string('description')->nullable();
                $table->integer('quantity')->default(0);
                $table->decimal('unit_price', 14, 4)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('line_total', 14, 2)->default(0);
                $table->integer('position')->default(0);
                $table->timestamps();

                $table->index(['supplier_invoice_id', 'position']);
            });
        }

        // 3. Three-way match results
        if (! Schema::hasTable('three_way_matches')) {
            Schema::create('three_way_matches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('po_id')->constrained('purchase_orders')->cascadeOnDelete();
                $table->foreignId('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->nullOnDelete();
                $table->string('status')->default('PENDING'); // PENDING | MATCHED | MISMATCH | BLOCKED
                $table->string('match_level')->default('NONE'); // NONE | HEADER | LINE | FULL
                $table->json('mismatches')->nullable();       // array of mismatch details
                $table->decimal('po_total', 14, 2)->default(0);
                $table->decimal('grn_total', 14, 2)->default(0);
                $table->decimal('invoice_total', 14, 2)->default(0);
                $table->decimal('variance_amount', 14, 2)->default(0);
                $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('matched_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['status']);
                $table->index(['po_id', 'supplier_invoice_id']);
                $table->unique(['po_id', 'supplier_invoice_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('three_way_matches');
        Schema::dropIfExists('supplier_invoice_items');
        if (Schema::hasColumn('supplier_invoices', 'po_id')) {
            Schema::table('supplier_invoices', function (Blueprint $table) {
                $table->dropIndex(['po_id', 'status']);
                $table->dropForeign(['po_id']);
                $table->dropColumn('po_id');
            });
        }
    }
};
