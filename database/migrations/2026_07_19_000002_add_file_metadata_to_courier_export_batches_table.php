<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_export_batches', function (Blueprint $table) {
            $table->bigInteger('file_size')->nullable()->after('file_path');
            $table->string('file_hash', 64)->nullable()->after('file_size');
            $table->timestamp('file_generated_at')->nullable()->after('file_hash');
        });
    }

    public function down(): void
    {
        Schema::table('courier_export_batches', function (Blueprint $table) {
            $table->dropColumn(['file_size', 'file_hash', 'file_generated_at']);
        });
    }
};
