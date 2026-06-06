<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // e.g. 'users.view', 'inventory.edit'
            $table->string('label');                   // e.g. 'View Users'
            $table->string('section');               // e.g. 'Users', 'Inventory', 'Settings'
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role');                  // matches User.role enum
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['role', 'permission_id']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('action');                // e.g. 'user.created', 'role.updated'
            $table->string('entity_type')->nullable(); // e.g. 'User', 'Role'
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('metadata')->nullable();     // old_values, new_values
            $table->string('ip_address')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
    }
};
