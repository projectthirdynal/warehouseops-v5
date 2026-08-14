<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_quality_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_version')->unique();
            $table->json('source_map')->nullable();
            $table->float('baseline_score')->default(50);
            $table->integer('sample_size')->default(0);
            $table->integer('positive_count')->default(0);
            $table->timestamp('trained_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_quality_models');
    }
};
