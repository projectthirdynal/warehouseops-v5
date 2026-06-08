<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'variant_id']);
            $table->unique(['product_id', 'variant_id', 'warehouse_id'], 'product_stocks_product_variant_warehouse_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropUnique('product_stocks_product_variant_warehouse_unique');
            $table->unique(['product_id', 'variant_id']);
        });
    }
};
