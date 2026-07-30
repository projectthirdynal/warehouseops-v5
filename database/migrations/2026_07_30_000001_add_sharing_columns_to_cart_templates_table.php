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
            $table->json('allowed_roles')->nullable()->after('is_shared');
            $table->foreignId('cloned_from')->nullable()->constrained('cart_templates', 'id')->nullOnDelete()->after('allowed_roles');
            $table->timestamp('last_used_at')->nullable()->after('cloned_from');
            $table->index(['is_shared', 'allowed_roles']);
        });
    }

    public function down(): void
    {
        Schema::table('cart_templates', function (Blueprint $table) {
            $table->dropIndex(['is_shared', 'allowed_roles']);
            $table->dropColumn(['allowed_roles', 'cloned_from', 'last_used_at']);
        });
    }
};
