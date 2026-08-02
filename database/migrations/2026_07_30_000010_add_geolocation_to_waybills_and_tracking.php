<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waybills', function (Blueprint $table) {
            if (! Schema::hasColumn('waybills', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('returned_at');
            }
            if (! Schema::hasColumn('waybills', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('waybills', 'last_location_at')) {
                $table->timestamp('last_location_at')->nullable()->after('longitude');
            }
            if (! Schema::hasColumn('waybills', 'last_location_description')) {
                $table->string('last_location_description')->nullable()->after('last_location_at');
            }
        });

        Schema::table('waybill_tracking_history', function (Blueprint $table) {
            if (! Schema::hasColumn('waybill_tracking_history', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            }
            if (! Schema::hasColumn('waybill_tracking_history', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
        });
    }

    public function down(): void
    {
        Schema::table('waybills', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'last_location_at', 'last_location_description']);
        });

        Schema::table('waybill_tracking_history', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
