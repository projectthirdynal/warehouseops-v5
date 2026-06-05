<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('third_party_id')->constrained()->cascadeOnDelete();

            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('title')->nullable();       // Mr, Ms, Dr, etc.
            $table->string('position')->nullable();    // Job title
            $table->string('department')->nullable();

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_alt')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['third_party_id', 'is_primary']);
            $table->index(['email']);
            $table->index(['phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
