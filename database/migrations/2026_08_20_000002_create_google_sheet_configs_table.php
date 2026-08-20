<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('google_sheet_configs')) {
            Schema::create('google_sheet_configs', function (Blueprint $table) {
                $table->id();
                $table->string('courier', 20);
                $table->string('month', 20);
                $table->integer('data_year');
                $table->text('sheet_url')->nullable();
                $table->string('sheet_tab_name')->nullable();
                $table->boolean('enabled')->default(true);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['courier', 'month', 'data_year']);
                $table->index(['data_year', 'enabled']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_sheet_configs');
    }
};
