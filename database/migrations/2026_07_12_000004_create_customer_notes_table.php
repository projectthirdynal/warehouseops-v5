<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note_type')->default('agent_note');
            $table->text('body');
            $table->jsonb('tags')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('pinned_until')->nullable();
            $table->index(['customer_id', 'note_type']);
            $table->index(['customer_id', 'created_at']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
    }
};
