<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('facebook_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft'); // draft, scheduled, sending, completed, cancelled
            $table->json('targeting')->nullable(); // {tags, risk_level, has_ordered, page_id, opt_in_only, assigned_agent_id, status}
            $table->string('split_type')->default('single'); // single, ab_test
            $table->integer('split_percentage')->default(50); // for A/B: % that gets variant A
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('read_count')->default(0);
            $table->integer('replied_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('broadcast_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('label'); // A, B, C...
            $table->text('body');
            $table->json('quick_replies')->nullable();
            $table->integer('recipient_count')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('read_count')->default(0);
            $table->integer('replied_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->timestamps();
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadcast_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending'); // pending, sent, delivered, read, replied, failed, skipped
            $table->text('error_message')->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcast_variants');
        Schema::dropIfExists('broadcast_campaigns');
    }
};
