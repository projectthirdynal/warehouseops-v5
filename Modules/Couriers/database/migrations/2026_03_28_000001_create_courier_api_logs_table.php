<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_provider_id')->nullable()->constrained('courier_providers')->nullOnDelete();
            $table->string('courier_code');
            $table->string('action');           // create_order, cancel_order, query_tracking, webhook, test_connection
            $table->string('direction');         // outbound, inbound
            $table->string('endpoint')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->integer('http_status')->nullable();
            $table->boolean('is_success')->default(false);
            $table->text('error_message')->nullable();
            $table->decimal('response_time_ms', 10, 2)->nullable();
            $table->foreignId('waybill_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['courier_code', 'action', 'created_at']);
            $table->index(['waybill_id']);
            $table->index(['is_success', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_api_logs');
    }
};
