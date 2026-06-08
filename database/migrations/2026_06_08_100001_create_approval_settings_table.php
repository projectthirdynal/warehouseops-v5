<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        DB::table('approval_settings')->insert([
            ['key' => 'pr_approver_roles',          'value' => 'admin,supervisor',      'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pr_approver_user_id',         'value' => null,                    'created_at' => now(), 'updated_at' => now()],
            ['key' => 'po_approver_roles',           'value' => 'admin,finance',         'created_at' => now(), 'updated_at' => now()],
            ['key' => 'po_approver_user_id',         'value' => null,                    'created_at' => now(), 'updated_at' => now()],
            ['key' => 'adjustment_approver_roles',   'value' => 'admin,supervisor,warehouse', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'adjustment_approver_user_id', 'value' => null,                    'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_settings');
    }
};
