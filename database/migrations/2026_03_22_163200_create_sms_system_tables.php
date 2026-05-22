<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SMS Campaigns table for bulk messaging
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('message');
            $table->enum('type', ['broadcast', 'sequence', 'reminder']);
            $table->enum('status', ['draft', 'scheduled', 'sending', 'completed', 'failed', 'paused'])->default('draft');
            $table->enum('target_audience', ['all_customers', 'delivered', 'pending', 'returned', 'custom'])->default('custom');
            $table->json('filters')->nullable(); // For custom filtering
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // SMS Sequences for automated follow-ups
        Schema::create('sms_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('trigger_event', [
                'waybill_created',
                'waybill_dispatched',
                'waybill_out_for_delivery',
                'waybill_delivered',
                'waybill_returned',
                'lead_created',
                'lead_sale',
            ]);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // SMS Sequence Steps
        Schema::create('sms_sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained('sms_sequences')->cascadeOnDelete();
            $table->integer('step_order');
            $table->text('message');
            $table->integer('delay_minutes')->default(0); // Delay after previous step
            $table->enum('delay_type', ['minutes', 'hours', 'days'])->default('minutes');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // SMS Log for tracking all sent messages
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('sms_campaigns')->nullOnDelete();
            $table->foreignId('sequence_id')->nullable()->constrained('sms_sequences')->nullOnDelete();
            $table->foreignId('waybill_id')->nullable()->constrained('waybills')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('phone', 20);
            $table->text('message');
            $table->enum('status', ['pending', 'sent', 'delivered', 'failed'])->default('pending');
            $table->string('external_id')->nullable(); // API response ID
            $table->text('error_message')->nullable();
            $table->decimal('cost', 8, 4)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'created_at']);
            $table->index(['campaign_id', 'status']);
            $table->index('status');
        });

        // SMS Templates for reusable messages
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('message');
            $table->string('category')->nullable(); // e.g., 'delivery', 'marketing', 'reminder'
            $table->json('variables')->nullable(); // Available placeholders
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // Sequence Enrollments - tracks recipients in sequences
        Schema::create('sms_sequence_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained('sms_sequences')->cascadeOnDelete();
            $table->foreignId('waybill_id')->nullable()->constrained('waybills')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('phone', 20);
            $table->integer('current_step')->default(0);
            $table->enum('status', ['active', 'completed', 'cancelled', 'paused'])->default('active');
            $table->timestamp('next_step_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['sequence_id', 'status']);
            $table->index('next_step_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_sequence_enrollments');
        Schema::dropIfExists('sms_templates');
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('sms_sequence_steps');
        Schema::dropIfExists('sms_sequences');
        Schema::dropIfExists('sms_campaigns');
    }
};
