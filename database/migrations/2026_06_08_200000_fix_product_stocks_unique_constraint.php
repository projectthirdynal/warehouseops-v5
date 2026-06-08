<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original product_stocks migration created a unique key on (product_id, variant_id).
 * The EIMS phase-1 migration added warehouse_id as a column but never updated the constraint,
 * so inserting the same product in two warehouses raises a DB unique violation.
 *
 * This migration drops the old 2-column constraint (if present) and ensures the
 * 3-column (product_id, variant_id, warehouse_id) constraint exists.
 * Uses Schema::getIndexes() — works on SQLite, PostgreSQL, and MySQL.
 */
return new class extends Migration
{
    private const OLD = 'product_stocks_product_id_variant_id_unique';
    private const NEW = 'product_stocks_product_variant_warehouse_unique';

    public function up(): void
    {
        $indexes = $this->indexNames();

        if (in_array(self::OLD, $indexes, true)) {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->dropUnique(self::OLD);
            });
        }

        if (! in_array(self::NEW, $indexes, true)) {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->unique(['product_id', 'variant_id', 'warehouse_id'], self::NEW);
            });
        }
    }

    public function down(): void
    {
        $indexes = $this->indexNames();

        if (in_array(self::NEW, $indexes, true)) {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->dropUnique(self::NEW);
            });
        }

        if (! in_array(self::OLD, $indexes, true)) {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->unique(['product_id', 'variant_id'], self::OLD);
            });
        }
    }

    /** @return string[] */
    private function indexNames(): array
    {
        return array_column(Schema::getIndexes('product_stocks'), 'name');
    }
};
