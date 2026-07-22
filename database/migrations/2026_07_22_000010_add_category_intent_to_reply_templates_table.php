<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reply_templates', function (Blueprint $table) {
            $table->string('category')->nullable()->after('title');
            $table->string('intent')->nullable()->after('category');
            $table->index(['category', 'is_active']);
            $table->index(['intent', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('reply_templates', function (Blueprint $table) {
            $table->dropIndex(['category', 'is_active']);
            $table->dropIndex(['intent', 'is_active']);
            $table->dropColumn(['category', 'intent']);
        });
    }
};
