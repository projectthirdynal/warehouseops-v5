<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coaching_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category')->default('general');
            $table->string('priority')->default('medium');
            $table->text('subject');
            $table->text('body');
            $table->json('action_items')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'resolved_at']);
            $table->index(['author_id', 'created_at']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coaching_notes');
    }
};
