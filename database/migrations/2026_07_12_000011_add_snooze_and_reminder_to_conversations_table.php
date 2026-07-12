<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('snoozed_until')->nullable()->after('flagged_at');
            $table->timestamp('reminder_at')->nullable()->after('snoozed_until');
            $table->text('snooze_reason')->nullable()->after('reminder_at');
            $table->index('snoozed_until');
            $table->index('reminder_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['snoozed_until']);
            $table->dropIndex(['reminder_at']);
            $table->dropColumn(['snoozed_until', 'reminder_at', 'snooze_reason']);
        });
    }
};
