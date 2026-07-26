<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_ml_models', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('default');
            $table->string('version')->default('v1');
            $table->json('feature_weights')->nullable();
            $table->json('training_stats')->nullable();
            $table->integer('training_samples')->default(0);
            $table->float('accuracy')->nullable();
            $table->float('precision')->nullable();
            $table->float('recall')->nullable();
            $table->float('f1_score')->nullable();
            $table->timestamp('trained_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['name', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_ml_models');
    }
};
