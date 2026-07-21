<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_sales_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('frequency', 20)->default('weekly'); // daily, weekly, monthly
            $table->time('send_at')->default('08:00');
            $table->string('day_of_week', 10)->nullable(); // mon, tue, ... for weekly
            $table->integer('day_of_month')->nullable(); // 1-31 for monthly
            $table->string('format', 10)->default('csv'); // csv, json
            $table->integer('lookback_days')->default(7);
            $table->json('recipients')->nullable(); // email addresses
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_sales_reports');
    }
};
