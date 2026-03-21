<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Leads table
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recycling_pool_id')->nullable();
            $table->string('name');
            $table->string('phone');
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('barangay')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('status')->default('NEW');
            $table->string('sales_status')->default('NEW');
            $table->string('source')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('original_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_called_at')->nullable();
            $table->integer('call_attempts')->default(0);
            $table->text('notes')->nullable();
            $table->string('product_name')->nullable();
            $table->string('product_brand')->nullable();
            $table->string('previous_item')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->integer('total_cycles')->default(0);
            $table->integer('max_cycles')->default(3);
            $table->boolean('is_exhausted')->default(false);
            $table->integer('quality_score')->nullable();
            $table->timestamp('last_scored_at')->nullable();
            $table->integer('current_qa_level')->default(1);
            $table->boolean('qa_required')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['phone']);
            $table->index(['status']);
            $table->index(['sales_status']);
            $table->index(['assigned_to']);
            $table->index(['is_exhausted']);
        });

        // Lead cycles
        Schema::create('lead_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->integer('cycle_number');
            $table->foreignId('assigned_agent_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('ACTIVE');
            $table->string('outcome')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['lead_id', 'cycle_number']);
            $table->index(['status']);
            $table->index(['assigned_agent_id', 'status']);
        });

        // Lead logs
        Schema::create('lead_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });

        // Lead snapshots
        Schema::create('lead_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_id')->nullable()->constrained('lead_cycles')->nullOnDelete();
            $table->json('data');
            $table->string('trigger_reason');
            $table->timestamps();

            $table->index(['lead_id']);
        });

        // Lead recycling pool
        Schema::create('lead_recycling_pool', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('PENDING');
            $table->string('reason')->nullable();
            $table->integer('priority')->default(0);
            $table->integer('cooldown_hours')->default(12);
            $table->timestamp('available_at');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['assigned_agent_id', 'status']);
        });

        // QA Reviews
        Schema::create('qa_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->integer('qa_level')->default(1);
            $table->string('qa_status')->default('PENDING');
            $table->string('decision')->nullable();
            $table->text('notes')->nullable();
            $table->json('checklist')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'qa_level']);
            $table->index(['reviewer_id', 'qa_status']);
        });

        // QA Decision Reasons
        Schema::create('qa_decision_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->string('type'); // approve, reject, recycle
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_decision_reasons');
        Schema::dropIfExists('qa_reviews');
        Schema::dropIfExists('lead_recycling_pool');
        Schema::dropIfExists('lead_snapshots');
        Schema::dropIfExists('lead_logs');
        Schema::dropIfExists('lead_cycles');
        Schema::dropIfExists('leads');
    }
};
