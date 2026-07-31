<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('badges')) {
            Schema::create('badges', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('icon')->default('Award');
                $table->string('color', 20)->default('primary');
                $table->string('category', 50)->default('general');
                $table->string('criteria_type', 50)->nullable();
                $table->integer('criteria_value')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->index(['category', 'is_active']);
            });
        }

        if (! Schema::hasTable('agent_badges')) {
            Schema::create('agent_badges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamp('awarded_at')->useCurrent();
                $table->timestamps();
                $table->unique(['user_id', 'badge_id']);
                $table->index('awarded_at');
            });
        }

        if (! Schema::hasTable('agent_streaks')) {
            Schema::create('agent_streaks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('streak_type', 50)->default('daily_activity');
                $table->integer('current_streak')->default(0);
                $table->integer('longest_streak')->default(0);
                $table->date('last_activity_date')->nullable();
                $table->date('streak_started_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'streak_type']);
            });
        }

        if (! Schema::hasTable('milestones')) {
            Schema::create('milestones', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('metric', 50);
                $table->integer('target_value');
                $table->string('period', 20)->default('all_time');
                $table->foreignId('reward_badge_id')->nullable()->constrained('badges')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->index(['metric', 'is_active']);
            });
        }

        if (! Schema::hasTable('agent_milestones')) {
            Schema::create('agent_milestones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
                $table->integer('current_value')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'milestone_id']);
                $table->index('completed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_milestones');
        Schema::dropIfExists('milestones');
        Schema::dropIfExists('agent_streaks');
        Schema::dropIfExists('agent_badges');
        Schema::dropIfExists('badges');
    }
};
