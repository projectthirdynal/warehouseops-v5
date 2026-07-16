<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_remarks', function (Blueprint $table) {
            $table->string('visibility')->default('internal')->after('type');
            $table->index(['visibility']);
        });
    }

    public function down(): void
    {
        Schema::table('order_remarks', function (Blueprint $table) {
            $table->dropIndex(['visibility']);
            $table->dropColumn('visibility');
        });
    }
};
