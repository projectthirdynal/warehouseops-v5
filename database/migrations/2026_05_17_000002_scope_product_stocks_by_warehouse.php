<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'variant_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX product_stocks_product_variant_warehouse_unique
                 ON product_stocks (product_id, COALESCE(variant_id, 0), COALESCE(warehouse_id, 0))'
            );

            return;
        }

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->unique(['product_id', 'variant_id', 'warehouse_id'], 'product_stocks_product_variant_warehouse_unique');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS product_stocks_product_variant_warehouse_unique');
        } else {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->dropUnique('product_stocks_product_variant_warehouse_unique');
            });
        }

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->unique(['product_id', 'variant_id']);
        });
    }
};
