<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('moderation_status')->default('approved')->after('send_error');
            $table->text('moderation_note')->nullable()->after('moderation_status');
            $table->timestamp('moderated_at')->nullable()->after('moderation_note');
            $table->foreignId('moderated_by')->nullable()->after('moderated_at');

            $table->index(['moderation_status', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['moderation_status', 'direction']);
            $table->dropColumn(['moderation_status', 'moderation_note', 'moderated_at', 'moderated_by']);
        });
    }
};
