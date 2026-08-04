<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movement_audit_trails', function (Blueprint $table) {
            $table->id();
            $table->morphs('movement'); // inventory_movement or supply_movement
            $table->morphs('stockable'); // product or supply
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30); // STOCK_IN, STOCK_OUT, ADJUSTMENT, WRITE_OFF, RESERVATION, RELEASE, RETURN
            $table->integer('quantity'); // delta
            $table->integer('before_quantity')->default(0);
            $table->integer('after_quantity')->default(0);
            $table->integer('before_reserved')->default(0);
            $table->integer('after_reserved')->default(0);
            $table->string('reason_code', 60)->nullable();
            $table->text('reason_notes')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['stockable_type', 'stockable_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['warehouse_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_audit_trails');
    }
};
