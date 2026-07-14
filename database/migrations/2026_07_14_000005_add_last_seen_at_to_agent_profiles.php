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
            $table->timestamp('last_seen_at')->nullable()->after('is_available');
            $table->index(['is_available', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropIndex(['is_available', 'last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
