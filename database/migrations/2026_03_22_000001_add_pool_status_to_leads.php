<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('pool_status')->default('AVAILABLE')->after('is_exhausted');
            $table->timestamp('cooldown_until')->nullable()->after('pool_status');
            $table->index(['pool_status']);
            $table->index(['pool_status', 'cooldown_until']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['pool_status']);
            $table->dropIndex(['pool_status', 'cooldown_until']);
            $table->dropColumn(['pool_status', 'cooldown_until']);
        });
    }
};
