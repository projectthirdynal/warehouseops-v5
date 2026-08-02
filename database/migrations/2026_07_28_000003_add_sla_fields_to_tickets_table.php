<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tickets', 'due_at')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->timestamp('due_at')->nullable()->after('related_lead');
                $table->timestamp('resolved_at')->nullable()->after('due_at');
                $table->index('due_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['due_at']);
            $table->dropColumn(['due_at', 'resolved_at']);
        });
    }
};
