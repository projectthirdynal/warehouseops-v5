<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('sent_at');
            $table->string('send_status')->nullable()->after('read_at');
            $table->text('send_error')->nullable()->after('send_status');
            $table->unsignedInteger('retry_count')->default(0)->after('send_error');

            $table->index(['conversation_id', 'send_status']);
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'send_status']);
            $table->dropIndex(['read_at']);
            $table->dropColumn(['read_at', 'send_status', 'send_error', 'retry_count']);
        });
    }
};
