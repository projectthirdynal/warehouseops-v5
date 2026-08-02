<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('last_customer_message_at')->nullable()->after('last_message_at');
            $table->timestamp('response_window_expires_at')->nullable()->after('last_customer_message_at');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('provider_message_id')->nullable()->after('external_message_id');
            $table->string('send_status')->nullable()->change();
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->text('failure_code')->nullable()->after('send_error');
            $table->text('failure_message')->nullable()->after('failure_code');
            $table->foreignId('agent_id')->nullable()->after('failure_message')->constrained('users')->nullOnDelete();

            $table->index(['provider_message_id']);
            $table->index(['send_status']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropIndex(['provider_message_id']);
            $table->dropIndex(['send_status']);
            $table->dropColumn([
                'provider_message_id',
                'delivered_at',
                'failure_code',
                'failure_message',
                'agent_id',
            ]);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn([
                'last_customer_message_at',
                'response_window_expires_at',
            ]);
        });
    }
};
