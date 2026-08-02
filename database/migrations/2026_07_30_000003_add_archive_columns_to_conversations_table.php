<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('conversations', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('resolved_at');
            }
            if (! Schema::hasColumn('conversations', 'compressed_at')) {
                $table->timestamp('compressed_at')->nullable()->after('archived_at');
            }
            if (! Schema::hasColumn('conversations', 'message_count')) {
                $table->integer('message_count')->default(0)->after('unread_count');
            }
        });

        // Index for finding conversations eligible for archiving
        try {
            Schema::table('conversations', function (Blueprint $table) {
                $table->index(['status', 'archived_at'], 'conversations_status_archived_at_index');
            });
        } catch (Exception $e) {
            // Index already exists — ignore
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_status_archived_at_index');
            $table->dropColumn(['archived_at', 'compressed_at', 'message_count']);
        });
    }
};
