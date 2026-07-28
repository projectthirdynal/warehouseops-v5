<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->tinyInteger('satisfaction_rating')->nullable()->after('resolved_at');
            $table->text('satisfaction_comment')->nullable()->after('satisfaction_rating');
            $table->timestamp('satisfaction_submitted_at')->nullable()->after('satisfaction_comment');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['satisfaction_rating', 'satisfaction_comment', 'satisfaction_submitted_at']);
        });
    }
};
