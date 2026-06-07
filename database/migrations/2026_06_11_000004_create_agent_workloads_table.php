<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_workloads', function (Blueprint $table) {
            $table->foreignId('agent_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->integer('active_leads_count')->default(0);
            $table->integer('today_assigned_count')->default(0);
            $table->integer('today_converted_count')->default(0);
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamp('next_available_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_workloads');
    }
};
