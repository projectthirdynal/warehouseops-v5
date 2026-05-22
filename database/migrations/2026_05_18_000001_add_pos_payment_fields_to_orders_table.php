<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method')->default('COD')->after('shipping_cost');
            $table->string('payment_status')->default('UNPAID')->after('payment_method');
            $table->decimal('paid_amount', 10, 2)->default(0)->after('payment_status');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('paid_amount');
            $table->decimal('surcharge_amount', 10, 2)->default(0)->after('discount_amount');

            $table->index(['payment_method']);
            $table->index(['payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_method']);
            $table->dropIndex(['payment_status']);
            $table->dropColumn([
                'payment_method',
                'payment_status',
                'paid_amount',
                'discount_amount',
                'surcharge_amount',
            ]);
        });
    }
};
