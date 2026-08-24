<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_price_remarks', function (Blueprint $table) {
            $table->id();
            $table->string('price_key');
            $table->text('remarks');
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('price_key');
            $table->index(['price_key', 'remarks']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_price_remarks');
    }
};
