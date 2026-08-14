<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint');
            $table->string('p256dh_key')->nullable();
            $table->string('auth_key')->nullable();
            $table->string('content_encoding')->default('aesgcm');
            $table->timestamp('subscribed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'endpoint']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
