<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widget_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('dashboard', 50)->default('sales');
            $table->string('widget_key', 100);
            $table->boolean('is_visible')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dashboard', 'widget_key']);
            $table->index(['user_id', 'dashboard']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widget_configs');
    }
};
