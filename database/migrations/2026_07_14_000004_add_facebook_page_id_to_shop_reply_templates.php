<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_reply_templates', function (Blueprint $table) {
            $table->foreignId('facebook_page_id')->nullable()->after('created_by')->constrained('facebook_pages')->nullOnDelete();
            $table->index(['facebook_page_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('shop_reply_templates', function (Blueprint $table) {
            $table->dropIndex(['facebook_page_id', 'is_active', 'sort_order']);
            $table->dropColumn('facebook_page_id');
        });
    }
};
