<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->jsonb('tags')->nullable()->after('facebook_name');
            $table->index('tags', 'customers_tags_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_tags_index');
            $table->dropColumn('tags');
        });
    }
};
