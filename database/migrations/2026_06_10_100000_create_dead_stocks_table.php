<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dead_stocks', function (Blueprint $table) {
            $table->id();

            // Polymorphic: item_type = 'supply' | 'product'
            $table->string('item_type', 20);            // 'supply' | 'product'
            $table->unsignedBigInteger('supply_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();

            $table->unsignedBigInteger('warehouse_id')->nullable();

            $table->unsignedInteger('quantity');         // declared dead quantity
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total_value', 14, 4)->default(0);

            $table->string('reason', 500)->nullable();   // why it's declared dead
            $table->unsignedBigInteger('recorded_by')->nullable();

            $table->timestamps();

            $table->foreign('supply_id')->references('id')->on('supplies')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['item_type', 'supply_id']);
            $table->index(['item_type', 'product_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_stocks');
    }
};
