<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reply_templates', function (Blueprint $table) {
            $table->string('approval_status')->nullable()->after('is_active');
            $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('rejection_reason')->nullable()->after('approved_at');

            $table->index(['approval_status', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('reply_templates', function (Blueprint $table) {
            $table->dropIndex(['approval_status', 'is_active']);
            $table->dropColumn(['approval_status', 'approved_by', 'approved_at', 'rejection_reason']);
        });
    }
};
