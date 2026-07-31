<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'discount_amount')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 2)->default(0)->after('shipping_cost');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'discount_amount')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
