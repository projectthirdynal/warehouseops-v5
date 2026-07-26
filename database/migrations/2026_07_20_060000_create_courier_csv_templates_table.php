<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_csv_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('courier_code', 30);
            $table->json('columns');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['courier_code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_csv_templates');
    }
};
