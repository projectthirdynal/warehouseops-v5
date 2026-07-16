<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_remarks', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('order_remarks')
                ->cascadeOnDelete();

            $table->index(['order_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_remarks', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'parent_id']);
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
