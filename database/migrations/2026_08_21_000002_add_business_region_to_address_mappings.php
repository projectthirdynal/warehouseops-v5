<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_mappings', function (Blueprint $table) {
            // Business grouping: NCR, North Luzon, South Luzon, Visayas, Mindanao
            // Distinct from the PSGC administrative `region` field already on the table.
            $table->string('business_region')->nullable()->after('island_group');
            $table->index(['business_region']);
        });
    }

    public function down(): void
    {
        Schema::table('address_mappings', function (Blueprint $table) {
            $table->dropIndex(['business_region']);
            $table->dropColumn('business_region');
        });
    }
};
