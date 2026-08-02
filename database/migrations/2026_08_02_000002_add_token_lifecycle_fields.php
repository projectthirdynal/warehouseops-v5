<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_accounts', function (Blueprint $table) {
            $table->timestamp('data_access_expires_at')->nullable()->after('token_expires_at');
            $table->timestamp('last_validated_at')->nullable()->after('data_access_expires_at');
            $table->text('last_validation_error')->nullable()->after('last_validated_at');
            $table->string('connection_status')->default('active')->after('last_validation_error')->index();
            $table->timestamp('reconnect_required_at')->nullable()->after('connection_status');
        });

        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->string('connection_status')->default('active')->after('connected_status')->index();
            $table->timestamp('last_webhook_at')->nullable()->after('last_sync_at');
            $table->timestamp('last_validated_at')->nullable()->after('last_webhook_at');
            $table->text('last_validation_error')->nullable()->after('last_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->dropColumn([
                'connection_status',
                'last_webhook_at',
                'last_validated_at',
                'last_validation_error',
            ]);
        });

        Schema::table('facebook_accounts', function (Blueprint $table) {
            $table->dropIndex(['connection_status']);
            $table->dropColumn([
                'data_access_expires_at',
                'last_validated_at',
                'last_validation_error',
                'connection_status',
                'reconnect_required_at',
            ]);
        });
    }
};
