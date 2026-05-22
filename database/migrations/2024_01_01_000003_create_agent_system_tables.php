<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add role and is_active to users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('agent')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
            $table->string('phone')->nullable()->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('phone');
            $table->softDeletes();
        });

        // Agent profiles
        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('max_active_cycles')->default(10);
            $table->json('product_skills')->nullable();
            $table->json('regions')->nullable();
            $table->decimal('priority_weight', 3, 2)->default(1.00);
            $table->boolean('is_available')->default(true);
            $table->integer('performance_score')->default(50);
            $table->timestamp('last_assignment_at')->nullable();
            $table->timestamps();

            $table->index(['is_available']);
        });

        // Agent flags
        Schema::create('agent_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->string('flag_type');
            $table->string('severity')->default('warning');
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'is_resolved']);
            $table->index(['flag_type', 'is_resolved']);
        });

        // Audit log (unified)
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->morphs('auditable');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // Settings (key-value store)
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->string('group')->default('general');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['group']);
        });

        // Webhook logs
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_type')->nullable();
            $table->json('payload');
            $table->string('signature')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('processing_status')->default('pending');
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['processing_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('agent_flags');
        Schema::dropIfExists('agent_profiles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'phone', 'last_login_at']);
            $table->dropSoftDeletes();
        });
    }
};
