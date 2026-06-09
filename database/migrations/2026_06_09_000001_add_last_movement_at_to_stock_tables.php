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
        // Written without UPDATE aliases for SQLite compatibility.
        DB::statement('
            UPDATE product_stocks
            SET last_movement_at = (
                SELECT MAX(inventory_movements.created_at)
                FROM inventory_movements
                WHERE inventory_movements.product_id = product_stocks.product_id
                  AND (
                      inventory_movements.warehouse_id = product_stocks.warehouse_id
                      OR (inventory_movements.warehouse_id IS NULL AND product_stocks.warehouse_id IS NULL)
                  )
            )
            WHERE EXISTS (
                SELECT 1 FROM inventory_movements
                WHERE inventory_movements.product_id = product_stocks.product_id
            )
        ');

        // Backfill supply_stocks from supply_movements
        DB::statement('
            UPDATE supply_stocks
            SET last_movement_at = (
                SELECT MAX(supply_movements.created_at)
                FROM supply_movements
                WHERE supply_movements.supply_id = supply_stocks.supply_id
                  AND (
                      supply_movements.warehouse_id = supply_stocks.warehouse_id
                      OR (supply_movements.warehouse_id IS NULL AND supply_stocks.warehouse_id IS NULL)
                  )
            )
            WHERE EXISTS (
                SELECT 1 FROM supply_movements
                WHERE supply_movements.supply_id = supply_stocks.supply_id
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
