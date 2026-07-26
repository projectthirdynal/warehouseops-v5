<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_merge_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('target_customer_id')->index();
            $table->unsignedBigInteger('source_customer_id')->index();
            $table->float('confidence_score')->default(0); // 0-100
            $table->json('match_reasons')->nullable(); // ["phone", "name", "psid", "address"]
            $table->json('merge_preview')->nullable(); // cached preview data
            $table->string('status')->default('pending')->index(); // pending, approved, rejected, merged
            $table->unsignedBigInteger('actioned_by')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->text('action_note')->nullable();
            $table->timestamps();

            $table->unique(['target_customer_id', 'source_customer_id'], 'auto_merge_pair_unique');
            $table->index(['status', 'confidence_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_merge_suggestions');
    }
};
