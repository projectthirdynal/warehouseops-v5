<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            if (!Schema::hasColumn('claims', 'auto_created')) {
                $table->boolean('auto_created')->default(false)->after('status');
            }
            if (!Schema::hasColumn('claims', 'source')) {
                $table->string('source')->default('manual')->after('auto_created');
            }
        });

        if (!Schema::hasColumn('claims', 'auto_created')) {
            Schema::table('claims', function (Blueprint $table) {
                $table->index(['auto_created', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            if (Schema::hasColumn('claims', 'auto_created')) {
                $table->dropIndex(['auto_created', 'status']);
                $table->dropColumn('auto_created');
            }
            if (Schema::hasColumn('claims', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
