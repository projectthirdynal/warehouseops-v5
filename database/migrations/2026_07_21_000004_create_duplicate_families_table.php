<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_families', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index(); // customer, order, conversation
            $table->string('group_key')->nullable(); // phone, psid, name+address, etc.
            $table->string('group_method')->nullable(); // phone, psid, fuzzy
            $table->unsignedBigInteger('anchor_ref_id')->nullable(); // anchor entity ID (e.g. customer_id)
            $table->string('anchor_label')->nullable(); // anchor display label
            $table->unsignedInteger('member_count')->default(0);
            $table->unsignedInteger('merged_count')->default(0);
            $table->string('status')->default('active')->index(); // active, merged, dismissed
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('actioned_by')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->text('action_note')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['group_key', 'type']);
        });

        Schema::create('duplicate_family_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('family_id')->index();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_anchor')->default(false);
            $table->json('member_data')->nullable(); // cached customer summary
            $table->string('match_reason')->nullable();
            $table->float('similarity_score')->nullable();
            $table->timestamps();

            $table->foreign('family_id')->references('id')->on('duplicate_families')->cascadeOnDelete();
            $table->index(['family_id', 'is_anchor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_family_members');
        Schema::dropIfExists('duplicate_families');
    }
};
