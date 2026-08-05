<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_locations', function (Blueprint $table): void {
            $table->integer('row_index')->nullable()->after('type');
            $table->integer('col_index')->nullable()->after('row_index');
            $table->string('zone_color', 20)->nullable()->after('col_index');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_locations', function (Blueprint $table): void {
            $table->dropColumn(['row_index', 'col_index', 'zone_color']);
        });
    }
};
