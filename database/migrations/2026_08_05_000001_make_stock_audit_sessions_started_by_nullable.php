<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_audit_sessions', function (Blueprint $table) {
            $table->dropForeign(['started_by']);
            $table->foreignId('started_by')->nullable()->change();
            $table->foreign('started_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_audit_sessions', function (Blueprint $table) {
            $table->dropForeign(['started_by']);
            $table->foreignId('started_by')->nullable(false)->change();
            $table->foreign('started_by')->references('id')->on('users');
        });
    }
};
