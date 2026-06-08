<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The original product_stocks migration created a unique key on (product_id, variant_id).
 * The EIMS phase-1 migration added warehouse_id as a column but never updated the constraint,
 * so any attempt to store the same product in two warehouses fails with a DB unique violation.
 *
 * This migration drops the old 2-column constraint and replaces it with a 3-column one
 * that includes warehouse_id, matching the application-level firstOrCreate lookup key.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the old 2-column constraint only if it still exists.
        // Some environments may have already dropped it or never had it.
        $oldConstraint = 'product_stocks_product_id_variant_id_unique';
        $newConstraint = 'product_stocks_product_variant_warehouse_unique';

        $existing = DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'product_stocks'::regclass AND conname = ?",
            [$oldConstraint]
        );

        if (!empty($existing)) {
            Schema::table('product_stocks', function (Blueprint $table) use ($oldConstraint) {
                $table->dropUnique($oldConstraint);
            });
        }

        // Add the 3-column constraint only if it doesn't already exist.
        $alreadyNew = DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'product_stocks'::regclass AND conname = ?",
            [$newConstraint]
        );

        if (empty($alreadyNew)) {
            Schema::table('product_stocks', function (Blueprint $table) use ($newConstraint) {
                $table->unique(['product_id', 'variant_id', 'warehouse_id'], $newConstraint);
            });
        }
    }

    public function down(): void
    {
        $newConstraint = 'product_stocks_product_variant_warehouse_unique';
        $oldConstraint = 'product_stocks_product_id_variant_id_unique';

        $existing = DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'product_stocks'::regclass AND conname = ?",
            [$newConstraint]
        );

        if (!empty($existing)) {
            Schema::table('product_stocks', function (Blueprint $table) use ($newConstraint) {
                $table->dropUnique($newConstraint);
            });
        }

        $alreadyOld = DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'product_stocks'::regclass AND conname = ?",
            [$oldConstraint]
        );

        if (empty($alreadyOld)) {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->unique(['product_id', 'variant_id']);
            });
        }
    }
};
