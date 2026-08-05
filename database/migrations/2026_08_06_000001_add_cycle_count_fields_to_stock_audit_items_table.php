<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_audit_items', function (Blueprint $table) {
            $table->foreignId('counted_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable()->after('counted_by');
            $table->foreignId('adjustment_id')->nullable()->after('counted_at')->constrained('stock_adjustments')->nullOnDelete();
        });

        Schema::table('stock_audit_sessions', function (Blueprint $table) {
            $table->boolean('auto_generated')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('stock_audit_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counted_by');
            $table->dropColumn('counted_at');
            $table->dropConstrainedForeignId('adjustment_id');
        });

        Schema::table('stock_audit_sessions', function (Blueprint $table) {
            $table->dropColumn('auto_generated');
        });
    }
};
