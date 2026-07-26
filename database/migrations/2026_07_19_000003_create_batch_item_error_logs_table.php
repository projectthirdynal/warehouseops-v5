<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_item_error_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_export_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_export_row_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error_type')->default('export');
            $table->text('error_message');
            $table->string('severity')->default('error');
            $table->string('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['courier_export_batch_id']);
            $table->index(['courier_export_row_id']);
            $table->index(['order_id']);
            $table->index(['severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_item_error_logs');
    }
};
