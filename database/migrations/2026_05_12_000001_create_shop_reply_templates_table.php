<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_reply_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('message');
            $table->string('category')->nullable();
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['category']);
        });

        DB::table('shop_reply_templates')->insert([
            [
                'name' => 'Same Address Check',
                'message' => "Hello po, same address pa rin po ba ito?\n{address}",
                'category' => 'confirmation',
                'variables' => json_encode(['{address}']),
                'is_active' => true,
                'sort_order' => 10,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Ask Complete Details',
                'message' => 'Hello po {customer_name}, paki-send po complete name, complete address, landmark, and active phone number para ma-process po ang order ninyo.',
                'category' => 'details',
                'variables' => json_encode(['{customer_name}']),
                'is_active' => true,
                'sort_order' => 20,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Confirm Order',
                'message' => 'Confirm ko lang po ang order ninyo sa {page_name}. Paki-check po kung tama ang product, quantity, complete address, and COD amount bago namin i-process.',
                'category' => 'confirmation',
                'variables' => json_encode(['{page_name}']),
                'is_active' => true,
                'sort_order' => 30,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_reply_templates');
    }
};
