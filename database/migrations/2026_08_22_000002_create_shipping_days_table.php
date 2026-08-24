<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_days', function (Blueprint $table) {
            $table->id();
            $table->string('province');
            $table->string('city');
            $table->string('barangay')->nullable();
            $table->unsignedSmallInteger('shipping_days');
            $table->timestamps();

            $table->index(['province', 'city']);
            $table->index(['province', 'city', 'barangay']);
            $table->index('province');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_days');
    }
};
