<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_pool_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 30)->unique();

            $table->foreignId('requested_by')->constrained('users');
            // team_id is nullable and has no FK constraint because a teams table
            // does not yet exist in the schema. When teams are introduced, a
            // separate migration can add the FK.
            $table->unsignedBigInteger('team_id')->nullable();

            // Brand / product scope
            $table->string('brand_name');
            $table->string('product_name')->nullable();

            // Geography scope — stored as filter values, not hard FKs, to allow
            // flexible combinations (region, province, city, barangay).
            $table->string('business_region')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();

            // Age range (in days, based on source waybill delivered_at)
            $table->unsignedInteger('lead_age_from')->default(0);
            $table->unsignedInteger('lead_age_to')->nullable();

            // Quantities
            $table->unsignedInteger('requested_quantity');
            $table->unsignedInteger('available_quantity_at_request')->default(0);
            $table->unsignedInteger('approved_quantity')->nullable();

            // Distribution method (equal, manual_quantity, round_robin)
            $table->string('distribution_method', 30)->default('equal');

            // Workflow status
            $table->string('status', 30)->default('PENDING_APPROVAL');

            // Approval / rejection
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('requested_by');
            $table->index('brand_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_pool_requests');
    }
};
