<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->string('section', 20)->default('STOCK')->after('category');
            $table->string('stock_category', 30)->nullable()->after('section');
            $table->string('opex_category', 30)->nullable()->after('stock_category');
            $table->string('stock_status', 20)->default('MOVING')->after('opex_category');
            $table->boolean('stock_status_override')->default(false)->after('stock_status');
            $table->string('delete_reason', 500)->nullable()->after('stock_status_override');

            $table->index('section');
            $table->index('stock_category');
            $table->index('stock_status');
        });

        DB::table('supplies')->update(['section' => 'STOCK']);
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropIndex(['section']);
            $table->dropIndex(['stock_category']);
            $table->dropIndex(['stock_status']);
            $table->dropColumn(['section', 'stock_category', 'opex_category', 'stock_status', 'stock_status_override', 'delete_reason']);
        });
    }
};
