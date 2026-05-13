<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_data_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('confirmation_code')->unique();
            $table->string('app_scoped_user_id')->nullable()->index();
            $table->string('status')->default('received')->index();
            $table->string('source')->default('meta_callback');
            $table->json('payload')->nullable();
            $table->json('result_summary')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_data_deletion_requests');
    }
};
