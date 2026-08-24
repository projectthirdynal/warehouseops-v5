<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->string('promo_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type'); // FREEBIE, BUNDLE, DISCOUNT
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('trigger_quantity')->default(1);
            $table->unsignedInteger('free_quantity')->default(0);
            $table->foreignId('free_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('free_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('free_item_name')->nullable();
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index(['product_id', 'is_active']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promos');
    }
};
