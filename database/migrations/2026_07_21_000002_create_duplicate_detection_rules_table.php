<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_detection_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->index(); // order, customer, conversation
            $table->string('match_method')->nullable(); // phone, psid, name, address, fuzzy
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->json('config')->nullable(); // thresholds, time_window, fields, etc.
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_enabled']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_detection_rules');
    }
};
