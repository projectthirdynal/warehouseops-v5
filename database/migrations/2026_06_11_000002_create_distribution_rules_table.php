<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('strategy'); // round_robin | weighted | skill_match | territory | hybrid
            $table->integer('priority')->default(0);
            $table->json('filters')->nullable(); // { product_skills: [], regions: [], sources: [] }
            $table->json('weight_formula')->nullable(); // { w_perf: 0.30, w_avail: 0.25, ... }
            $table->boolean('is_active')->default(true);
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_rules');
    }
};
