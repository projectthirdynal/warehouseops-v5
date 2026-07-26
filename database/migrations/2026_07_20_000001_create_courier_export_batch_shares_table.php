<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_export_batch_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_export_batch_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['courier_export_batch_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_export_batch_shares');
    }
};
