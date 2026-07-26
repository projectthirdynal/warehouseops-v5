<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reply_template_ab_tests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft'); // draft, active, paused, completed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->unsignedBigInteger('winning_variant_id')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('reply_template_ab_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ab_test_id')->constrained('reply_template_ab_tests')->cascadeOnDelete();
            $table->foreignId('reply_template_id')->constrained('reply_templates')->cascadeOnDelete();
            $table->string('variant_label')->default('A'); // A, B, C, ...
            $table->unsignedInteger('weight')->default(50); // traffic split percentage
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('uses')->default(0);
            $table->unsignedInteger('conversations_resolved')->default(0);
            $table->timestamps();

            $table->unique(['ab_test_id', 'variant_label']);
            $table->index(['ab_test_id', 'reply_template_id']);
        });

        // Add FK after both tables exist (self-referencing across tables)
        Schema::table('reply_template_ab_tests', function (Blueprint $table) {
            $table->foreign('winning_variant_id')->references('id')->on('reply_template_ab_variants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reply_template_ab_variants');
        Schema::dropIfExists('reply_template_ab_tests');
    }
};
