<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->decimal('distribution_weight', 3, 2)->default(1.00)->after('performance_score');
            $table->boolean('auto_assign_enabled')->default(true)->after('distribution_weight');
            $table->time('shift_start')->nullable()->after('auto_assign_enabled');
            $table->time('shift_end')->nullable()->after('shift_start');
            $table->integer('max_daily_leads')->default(50)->after('shift_end');
            $table->integer('concurrent_lead_cap')->nullable()->after('max_daily_leads');
            $table->json('preferred_lead_sources')->nullable()->after('concurrent_lead_cap');
            $table->json('excluded_regions')->nullable()->after('preferred_lead_sources');
            $table->json('category_skills')->nullable()->after('excluded_regions');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'distribution_weight',
                'auto_assign_enabled',
                'shift_start',
                'shift_end',
                'max_daily_leads',
                'concurrent_lead_cap',
                'preferred_lead_sources',
                'excluded_regions',
                'category_skills',
            ]);
        });
    }
};
