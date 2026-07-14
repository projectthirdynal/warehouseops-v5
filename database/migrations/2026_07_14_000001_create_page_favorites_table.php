<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facebook_page_id')->constrained('facebook_pages')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'facebook_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_favorites');
    }
};
