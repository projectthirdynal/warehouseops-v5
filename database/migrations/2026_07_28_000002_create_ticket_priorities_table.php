<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color', 20)->default('gray');
            $table->integer('level')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        $defaults = [
            ['name' => 'Low', 'slug' => 'low', 'color' => 'gray', 'level' => 1, 'sort_order' => 1],
            ['name' => 'Medium', 'slug' => 'medium', 'color' => 'amber', 'level' => 2, 'sort_order' => 2],
            ['name' => 'High', 'color' => 'orange', 'slug' => 'high', 'level' => 3, 'sort_order' => 3],
            ['name' => 'Urgent', 'slug' => 'urgent', 'color' => 'red', 'level' => 4, 'sort_order' => 4],
        ];

        foreach ($defaults as $pri) {
            DB::table('ticket_priorities')->insert(array_merge($pri, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_priorities');
    }
};
