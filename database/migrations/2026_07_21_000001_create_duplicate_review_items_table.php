<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_review_items', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index(); // order, customer, conversation
            $table->unsignedBigInteger('primary_ref_id')->nullable(); // order_id, customer_id, conversation_id
            $table->unsignedBigInteger('duplicate_ref_id')->nullable(); // the duplicate's id
            $table->string('primary_label')->nullable(); // order_number, customer name, etc.
            $table->string('duplicate_label')->nullable();
            $table->string('match_method')->nullable(); // phone, psid, name, address, fuzzy
            $table->float('similarity_score')->nullable();
            $table->string('severity')->default('low'); // none, low, medium, high
            $table->string('status')->default('pending')->index(); // pending, reviewed, dismissed, actioned
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['severity', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_review_items');
    }
};
