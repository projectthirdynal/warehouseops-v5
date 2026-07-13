<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_export_batches', function (Blueprint $table) {
            $table->string('region')->nullable()->after('courier_code');
            $table->index(['courier_code', 'region']);
        });
    }

    public function down(): void
    {
        Schema::table('courier_export_batches', function (Blueprint $table) {
            $table->dropIndex(['courier_code', 'region']);
            $table->dropColumn('region');
        });
    }
};
