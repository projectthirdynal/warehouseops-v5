<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recycling_rules', function (Blueprint $table) {
            $table->id();
            $table->string('outcome')->unique();
            $table->integer('cooldown_hours')->default(24);
            $table->integer('max_cycles')->default(3);
            $table->string('next_action')->default('RECYCLE');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Insert default rules
        DB::table('recycling_rules')->insert([
            ['outcome' => 'NO_ANSWER', 'cooldown_hours' => 24, 'max_cycles' => 5, 'next_action' => 'RECYCLE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['outcome' => 'CALLBACK', 'cooldown_hours' => 0, 'max_cycles' => 999, 'next_action' => 'RECYCLE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['outcome' => 'INTERESTED', 'cooldown_hours' => 48, 'max_cycles' => 3, 'next_action' => 'RECYCLE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['outcome' => 'NOT_INTERESTED', 'cooldown_hours' => 720, 'max_cycles' => 2, 'next_action' => 'EXHAUST', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['outcome' => 'WRONG_NUMBER', 'cooldown_hours' => 0, 'max_cycles' => 1, 'next_action' => 'EXHAUST', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['outcome' => 'ORDERED', 'cooldown_hours' => 1440, 'max_cycles' => 999, 'next_action' => 'RECYCLE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('recycling_rules');
    }
};
