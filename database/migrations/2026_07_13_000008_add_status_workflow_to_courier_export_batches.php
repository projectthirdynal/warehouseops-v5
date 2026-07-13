<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_export_batches', function (Blueprint $table) {
            $table->timestamp('downloaded_at')->nullable()->after('exported_at');
            $table->timestamp('archived_at')->nullable()->after('downloaded_at');
            $table->index('archived_at');
        });

        // Migrate existing 'exported' status to 'ready'
        DB::table('courier_export_batches')
            ->where('status', 'exported')
            ->update(['status' => 'ready']);
    }

    public function down(): void
    {
        Schema::table('courier_export_batches', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['downloaded_at', 'archived_at']);
        });

        DB::table('courier_export_batches')
            ->where('status', 'ready')
            ->update(['status' => 'exported']);
    }
};
