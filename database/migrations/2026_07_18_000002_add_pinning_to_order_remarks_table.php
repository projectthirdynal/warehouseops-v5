<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_remarks', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('mentions');
            $table->timestamp('pinned_at')->nullable()->after('is_pinned');
            $table->foreignId('pinned_by')->nullable()->after('pinned_at')
                ->constrained('users')->nullOnDelete();
            $table->index(['order_id', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::table('order_remarks', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'is_pinned']);
            $table->dropForeign(['pinned_by']);
            $table->dropColumn(['is_pinned', 'pinned_at', 'pinned_by']);
        });
    }
};
