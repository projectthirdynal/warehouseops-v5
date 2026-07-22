<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reply_template_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reply_template_id')->constrained('reply_templates')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['reply_template_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reply_template_usage');
    }
};
