<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('sentiment')->default('neutral')->after('resolution_time_seconds');
            $table->float('sentiment_score')->default(0)->after('sentiment');
            $table->index('sentiment');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['sentiment']);
            $table->dropColumn(['sentiment', 'sentiment_score']);
        });
    }
};
