<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->string('courier_code', 30)->default('MANUAL');
            $table->string('courier_zone', 50);
            $table->decimal('base_fee', 10, 2)->default(0);
            $table->decimal('per_kg_fee', 10, 2)->default(0);
            $table->decimal('weight_threshold_kg', 10, 2)->default(0);
            $table->decimal('cod_fee', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['courier_code', 'courier_zone']);
            $table->index(['courier_zone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
