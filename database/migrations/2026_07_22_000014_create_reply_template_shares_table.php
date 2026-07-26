<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reply_template_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reply_template_id')->constrained('reply_templates')->cascadeOnDelete();
            $table->foreignId('facebook_page_id')->constrained('facebook_pages')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['reply_template_id', 'facebook_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reply_template_shares');
    }
};
