<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_assignment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('from_agent_id')->nullable();
            $table->unsignedBigInteger('to_agent_id')->nullable();
            $table->unsignedBigInteger('assigned_by_id')->nullable();
            $table->string('reason')->default('manual');
            $table->timestamps();

            $table->foreign('from_agent_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('to_agent_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_by_id')->references('id')->on('users')->nullOnDelete();

            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_assignment_histories');
    }
};
