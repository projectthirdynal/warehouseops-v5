<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('label')->nullable();
            $table->text('canonical_address')->nullable();
            $table->string('landmark')->nullable();
            $table->string('barangay')->nullable();
            $table->string('city_municipality')->nullable();
            $table->string('province')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Philippines');
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();

            $table->boolean('is_default')->default(false);
            $table->string('source')->nullable(); // e.g. profile_update, order, manual
            $table->timestamp('used_at')->nullable();

            $table->index(['customer_id', 'is_default']);
            $table->index(['customer_id', 'city_municipality', 'province']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
