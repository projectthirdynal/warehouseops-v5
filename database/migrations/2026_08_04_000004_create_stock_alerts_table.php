<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->morphs('stockable'); // product or supply stock record
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('alert_type')->default('LOW_STOCK'); // LOW_STOCK, OUT_OF_STOCK, OVERSTOCK
            $table->integer('current_stock')->default(0);
            $table->integer('reserved_stock')->default(0);
            $table->integer('reorder_point')->default(0);
            $table->integer('suggested_reorder_qty')->default(0);
            $table->string('status')->default('OPEN'); // OPEN, ACKNOWLEDGED, RESOLVED
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['stockable_type', 'stockable_id', 'status']);
            $table->index(['alert_type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};
