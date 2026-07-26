<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_remarks', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('is_pinned');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX order_remarks_tags_gin_idx ON order_remarks USING GIN ((tags::jsonb))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS order_remarks_tags_gin_idx');
        }

        Schema::table('order_remarks', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
