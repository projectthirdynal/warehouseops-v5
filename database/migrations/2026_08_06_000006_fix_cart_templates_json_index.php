<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Convert allowed_roles from json to jsonb for GIN index support
        if (Schema::hasColumn('cart_templates', 'allowed_roles')) {
            DB::statement("ALTER TABLE cart_templates ALTER COLUMN allowed_roles TYPE jsonb USING allowed_roles::jsonb");
        }

        // Drop the failed composite index if it somehow exists
        DB::statement('DROP INDEX IF EXISTS cart_templates_is_shared_allowed_roles_index');

        // Ensure btree index on is_shared exists
        DB::statement('CREATE INDEX IF NOT EXISTS cart_templates_is_shared_index ON cart_templates (is_shared)');

        // GIN index for jsonb allowed_roles
        DB::statement('CREATE INDEX IF NOT EXISTS cart_templates_allowed_roles_gin_index ON cart_templates USING gin (allowed_roles)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS cart_templates_allowed_roles_gin_index');
        DB::statement('DROP INDEX IF EXISTS cart_templates_is_shared_index');

        if (Schema::hasColumn('cart_templates', 'allowed_roles')) {
            DB::statement("ALTER TABLE cart_templates ALTER COLUMN allowed_roles TYPE json USING allowed_roles::json");
        }
    }
};
