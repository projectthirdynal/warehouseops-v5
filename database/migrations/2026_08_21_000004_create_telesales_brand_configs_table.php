<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Telesales-specific brand configuration layer.
        // Uses products.brand (distinct string values) as the source of truth
        // for brand names — this table only holds telesales-specific settings.
        Schema::create('telesales_brand_configs', function (Blueprint $table) {
            $table->id();
            $table->string('brand_name')->unique();
            $table->string('display_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('match_patterns')->nullable();
            $table->integer('default_max_lead_age_days')->nullable();
            $table->string('default_distribution_method')->default('equal');
            $table->integer('max_pool_quantity')->nullable();
            $table->integer('priority')->default(0);
            $table->json('allowed_regions')->nullable();
            $table->json('allowed_teams')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telesales_brand_configs');
    }
};
