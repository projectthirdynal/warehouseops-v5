<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // assigned supervisor, null = broadcast
            $table->string('type')->index(); // review_item, auto_merge, family, high_severity
            $table->string('severity')->default('medium'); // low, medium, high, critical
            $table->string('title');
            $table->text('message');
            $table->string('entity_type')->nullable(); // order, customer, conversation
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action_url')->nullable();
            $table->string('action_label')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('read_by')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['type', 'severity']);
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_notifications');
    }
};
