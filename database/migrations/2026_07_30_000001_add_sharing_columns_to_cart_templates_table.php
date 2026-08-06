<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('cart_templates', 'allowed_roles')) {
                $table->json('allowed_roles')->nullable()->after('is_shared');
            }
            if (! Schema::hasColumn('cart_templates', 'cloned_from')) {
                $table->foreignId('cloned_from')->nullable()->constrained('cart_templates', 'id')->nullOnDelete()->after('allowed_roles');
            }
            if (! Schema::hasColumn('cart_templates', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('cloned_from');
            }
        });

        // Add btree index on is_shared only (composite index with json column is not supported by PostgreSQL)
        if (Schema::hasColumn('cart_templates', 'is_shared')) {
            try {
                Schema::table('cart_templates', function (Blueprint $table) {
                    $table->index('is_shared', 'cart_templates_is_shared_index');
                });
            } catch (Exception $e) {
                // Index already exists — ignore
            }
        }
    }

    public function down(): void
    {
        Schema::table('cart_templates', function (Blueprint $table) {
            $table->dropIndex('cart_templates_is_shared_index');
            $table->dropColumn(['allowed_roles', 'cloned_from', 'last_used_at']);
        });
    }
};
