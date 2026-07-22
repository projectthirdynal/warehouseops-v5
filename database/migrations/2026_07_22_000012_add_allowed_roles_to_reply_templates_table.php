<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reply_templates', function (Blueprint $table) {
            $table->json('allowed_roles')->nullable()->after('intent');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX reply_templates_allowed_roles_gin_idx ON reply_templates USING gin ((allowed_roles::jsonb) jsonb_path_ops)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS reply_templates_allowed_roles_gin_idx');
        }

        Schema::table('reply_templates', function (Blueprint $table) {
            $table->dropColumn('allowed_roles');
        });
    }
};
