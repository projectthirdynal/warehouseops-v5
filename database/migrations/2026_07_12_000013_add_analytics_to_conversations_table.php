<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('first_response_at')->nullable()->after('merged_into_id');
            $table->timestamp('resolved_at')->nullable()->after('first_response_at');
            $table->integer('first_response_time_seconds')->nullable()->after('resolved_at');
            $table->integer('resolution_time_seconds')->nullable()->after('first_response_time_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn([
                'first_response_at',
                'resolved_at',
                'first_response_time_seconds',
                'resolution_time_seconds',
            ]);
        });
    }
};
