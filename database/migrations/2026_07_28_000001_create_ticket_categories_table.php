<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color', 20)->default('gray');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed default categories
        $defaults = [
            ['name' => 'General', 'slug' => 'general', 'color' => 'gray', 'sort_order' => 1],
            ['name' => 'Waybill', 'slug' => 'waybill', 'color' => 'blue', 'sort_order' => 2],
            ['name' => 'Delivery', 'slug' => 'delivery', 'color' => 'green', 'sort_order' => 3],
            ['name' => 'Product', 'slug' => 'product', 'color' => 'purple', 'sort_order' => 4],
            ['name' => 'Billing', 'slug' => 'billing', 'color' => 'amber', 'sort_order' => 5],
            ['name' => 'Technical', 'slug' => 'technical', 'color' => 'red', 'sort_order' => 6],
            ['name' => 'Other', 'slug' => 'other', 'color' => 'gray', 'sort_order' => 7],
        ];

        foreach ($defaults as $cat) {
            \DB::table('ticket_categories')->insert(array_merge($cat, [
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_categories');
    }
};
