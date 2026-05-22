<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Products catalog
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('category')->nullable();
            $table->decimal('selling_price', 10, 2)->default(0);
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->integer('weight_grams')->default(0);
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_qa')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active']);
            $table->index(['category']);
            $table->index(['brand']);
        });

        // Product variants (e.g. "30 capsules", "60 capsules")
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('variant_name');
            $table->decimal('selling_price', 10, 2)->nullable(); // null = use product price
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->integer('weight_grams')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        // Inventory movements (append-only ledger)
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('type'); // STOCK_IN, STOCK_OUT, ADJUSTMENT, RETURN, RESERVATION, RELEASE
            $table->integer('quantity'); // positive for in, negative for out
            $table->string('reference_type')->nullable(); // App\Models\Waybill, App\Domain\Order\Models\Order
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index(['type']);
            $table->index(['reference_type', 'reference_id']);
        });

        // Product stock levels (denormalized for fast reads)
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->integer('current_stock')->default(0);
            $table->integer('reserved_stock')->default(0);
            $table->integer('reorder_point')->default(10);
            $table->timestamp('last_restock_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
