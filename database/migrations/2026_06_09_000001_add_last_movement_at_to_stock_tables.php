<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── product_stocks ────────────────────────────────────────────
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->timestamp('last_movement_at')->nullable()->after('last_restock_at');
            $table->index('last_movement_at');
        });

        // ── supply_stocks ─────────────────────────────────────────────
        Schema::table('supply_stocks', function (Blueprint $table) {
            $table->timestamp('last_movement_at')->nullable()->after('last_restock_at');
            $table->index('last_movement_at');
        });

        // Backfill product_stocks from the latest inventory_movement per (product_id, warehouse_id)
        DB::statement('
            UPDATE product_stocks ps
            SET last_movement_at = (
                SELECT MAX(im.created_at)
                FROM inventory_movements im
                WHERE im.product_id = ps.product_id
                  AND (im.warehouse_id = ps.warehouse_id OR (im.warehouse_id IS NULL AND ps.warehouse_id IS NULL))
            )
            WHERE EXISTS (
                SELECT 1 FROM inventory_movements im2
                WHERE im2.product_id = ps.product_id
            )
        ');

        // Backfill supply_stocks from supply_movements
        DB::statement('
            UPDATE supply_stocks ss
            SET last_movement_at = (
                SELECT MAX(sm.created_at)
                FROM supply_movements sm
                WHERE sm.supply_id = ss.supply_id
                  AND (sm.warehouse_id = ss.warehouse_id OR (sm.warehouse_id IS NULL AND ss.warehouse_id IS NULL))
            )
            WHERE EXISTS (
                SELECT 1 FROM supply_movements sm2
                WHERE sm2.supply_id = ss.supply_id
            )
        ');
    }

    public function down(): void
    {
        Schema::table('supply_stocks', function (Blueprint $table) {
            $table->dropIndex(['last_movement_at']);
            $table->dropColumn('last_movement_at');
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropIndex(['last_movement_at']);
            $table->dropColumn('last_movement_at');
        });
    }
};
