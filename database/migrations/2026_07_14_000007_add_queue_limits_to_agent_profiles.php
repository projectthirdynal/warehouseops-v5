<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->integer('max_active_conversations')->default(15)->after('concurrent_lead_cap');
            $table->boolean('overflow_enabled')->default(true)->after('max_active_conversations');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropColumn(['max_active_conversations', 'overflow_enabled']);
        });
    }
};
