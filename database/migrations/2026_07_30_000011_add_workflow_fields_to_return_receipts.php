<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('return_receipts', 'inventory_updated')) {
                $table->boolean('inventory_updated')->default(false)->after('notes');
            }
            if (!Schema::hasColumn('return_receipts', 'inventory_movement_id')) {
                $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete()->after('inventory_updated');
            }
            if (!Schema::hasColumn('return_receipts', 'finance_notified')) {
                $table->boolean('finance_notified')->default(false)->after('inventory_movement_id');
            }
            if (!Schema::hasColumn('return_receipts', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('finance_notified');
            }
        });
    }

    public function down(): void
    {
        Schema::table('return_receipts', function (Blueprint $table) {
            $table->dropForeign(['inventory_movement_id']);
            $table->dropColumn(['inventory_updated', 'inventory_movement_id', 'finance_notified', 'processed_at']);
        });
    }
};
