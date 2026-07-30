<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('courier_sync_logs')) {
            Schema::create('courier_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->string('run_id')->index();
                $table->string('courier_code')->nullable();
                $table->string('trigger')->default('scheduled');
                $table->integer('waybills_checked')->default(0);
                $table->integer('waybills_updated')->default(0);
                $table->integer('waybills_unchanged')->default(0);
                $table->integer('errors_count')->default(0);
                $table->json('errors')->nullable();
                $table->json('per_courier')->nullable();
                $table->integer('duration_ms')->default(0);
                $table->string('status')->default('completed');
                $table->timestamps();
            });

            Schema::table('courier_sync_logs', function (Blueprint $table) {
                $table->index(['courier_code', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_sync_logs');
    }
};
