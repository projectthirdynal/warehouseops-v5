<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('third_party_id')->constrained()->cascadeOnDelete();

            $table->string('type')->default('billing');    // billing | shipping | branch | other
            $table->string('label')->nullable();           // e.g. "Main Office", "Warehouse"
            $table->boolean('is_default')->default(false);

            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('barangay')->nullable();
            $table->string('city');
            $table->string('state_province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Philippines');

            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['third_party_id', 'type']);
            $table->index(['third_party_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
