<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('google_connections')) {
            Schema::create('google_connections', function (Blueprint $table) {
                $table->id();
                $table->string('google_user_id')->nullable()->index();
                $table->string('email')->nullable();
                $table->text('access_token');
                $table->text('refresh_token');
                $table->timestamp('expires_at')->nullable();
                $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('connected_at')->useCurrent();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_connections');
    }
};
