<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_exports', function (Blueprint $table) {
            $table->id();
            $table->string('export_number')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->integer('conversation_count')->default(0);
            $table->integer('message_count')->default(0);
            $table->string('file_path')->nullable();
            $table->json('filters')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_exports');
    }
};
