<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            if (! Schema::hasColumn('uploads', 'retry_count')) {
                $table->integer('retry_count')->default(0)->after('error_rows');
            }
            if (! Schema::hasColumn('uploads', 'retry_of')) {
                $table->foreignId('retry_of')->nullable()->after('retry_count')
                    ->constrained('uploads')->nullOnDelete();
            }
            if (! Schema::hasColumn('uploads', 'retry_status')) {
                $table->string('retry_status')->nullable()->after('retry_of');
            }
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            if (Schema::hasColumn('uploads', 'retry_status')) {
                $table->dropColumn('retry_status');
            }
            if (Schema::hasColumn('uploads', 'retry_of')) {
                $table->dropForeign(['retry_of']);
                $table->dropColumn('retry_of');
            }
            if (Schema::hasColumn('uploads', 'retry_count')) {
                $table->dropColumn('retry_count');
            }
        });
    }
};
