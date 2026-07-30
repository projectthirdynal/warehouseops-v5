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
            $table->string('media_type', 20)->default('text')->after('content');
            $table->json('media_config')->nullable()->after('media_type');
            $table->index(['media_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('reply_templates', function (Blueprint $table) {
            $table->dropIndex(['media_type', 'is_active']);
            $table->dropColumn(['media_type', 'media_config']);
        });
    }
};
