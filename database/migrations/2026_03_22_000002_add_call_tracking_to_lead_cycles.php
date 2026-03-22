<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_cycles', function (Blueprint $table) {
            $table->timestamp('last_call_at')->nullable()->after('closed_at');
            $table->integer('call_count')->default(0)->after('last_call_at');
            $table->timestamp('callback_at')->nullable()->after('call_count');
            $table->text('callback_notes')->nullable()->after('callback_at');
        });
    }

    public function down(): void
    {
        Schema::table('lead_cycles', function (Blueprint $table) {
            $table->dropColumn(['last_call_at', 'call_count', 'callback_at', 'callback_notes']);
        });
    }
};
