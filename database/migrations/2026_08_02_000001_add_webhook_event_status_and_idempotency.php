<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_webhook_events', function (Blueprint $table) {
            $table->string('event_key')->nullable()->after('event_id');
            $table->string('status')->default('received')->after('signature_valid')->index();
            $table->unsignedInteger('retry_count')->default(0)->after('status');
            $table->text('last_error')->nullable()->after('error_message');

            $table->index(['event_key']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('facebook_webhook_events', function (Blueprint $table) {
            $table->dropIndex(['event_key']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['event_key', 'status', 'retry_count', 'last_error']);
        });
    }
};
