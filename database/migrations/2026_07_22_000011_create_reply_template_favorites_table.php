<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reply_template_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reply_template_id')->constrained('reply_templates')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'reply_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reply_template_favorites');
    }
};
