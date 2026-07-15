<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_status_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_page_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('label');
            $table->timestamps();
            $table->unique(['facebook_page_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_status_labels');
    }
};
