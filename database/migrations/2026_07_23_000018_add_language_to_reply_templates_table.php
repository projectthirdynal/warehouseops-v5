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
            $table->string('language', 8)->default('en')->after('intent');
            $table->index(['language', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('reply_templates', function (Blueprint $table) {
            $table->dropIndex(['language', 'is_active']);
            $table->dropColumn('language');
        });
    }
};
