<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('address_correction_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('before');
            $table->json('after');
            $table->float('confidence_before')->default(0);
            $table->float('confidence_after')->default(0);
            $table->string('action')->default('manual_edit');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_correction_history');
    }
};
