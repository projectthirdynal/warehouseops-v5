<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictive_model_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->string('model_version')->default('v1');
            $table->float('conversion_rate')->default(0);
            $table->float('avg_handle_time_hrs')->default(0);
            $table->float('source_affinity_score')->default(0);
            $table->float('region_affinity_score')->default(0);
            $table->float('product_affinity_score')->default(0);
            $table->float('time_of_day_score')->default(0);
            $table->float('recency_score')->default(0);
            $table->float('overall_score')->default(0);
            $table->integer('total_cycles')->default(0);
            $table->integer('total_sales')->default(0);
            $table->json('feature_vector')->nullable();
            $table->timestamp('trained_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'model_version']);
            $table->index('overall_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictive_model_data');
    }
};
