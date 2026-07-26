<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_csv_validation_error_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_export_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_export_row_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('courier_code', 30)->nullable();
            $table->string('error_type', 50)->default('unknown');
            $table->text('error_message');
            $table->json('context')->nullable();
            $table->string('source', 100)->nullable();
            $table->timestamps();

            $table->index(['courier_code', 'error_type', 'created_at']);
            $table->index(['order_id', 'created_at']);
            $table->index(['courier_export_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_csv_validation_error_logs');
    }
};
