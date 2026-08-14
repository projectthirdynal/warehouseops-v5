<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_burnout_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('risk_score'); // 0-100
            $table->string('risk_level'); // low, medium, high, critical
            $table->json('features'); // computed feature values
            $table->text('recommendation');
            $table->string('model_version', 20)->default('v1');
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();

            $table->index(['risk_level', 'calculated_at']);
            $table->index(['calculated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_burnout_predictions');
    }
};
