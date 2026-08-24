<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_pools', function (Blueprint $table) {
            $table->id();
            $table->string('pool_number', 30)->unique();

            $table->foreignId('pool_request_id')->constrained('lead_pool_requests')->cascadeOnDelete();

            // Snapshot of request scope at approval time
            $table->string('brand_name');
            $table->string('product_name')->nullable();
            $table->string('business_region')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->unsignedInteger('lead_age_from')->default(0);
            $table->unsignedInteger('lead_age_to')->nullable();

            $table->unsignedBigInteger('team_id')->nullable();

            $table->unsignedInteger('approved_quantity');
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('distributed_quantity')->default(0);

            $table->string('distribution_method', 30)->default('equal');
            $table->string('status', 30)->default('READY');

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->index(['status', 'created_at']);
            $table->index('pool_request_id');
            $table->index('team_id');
            $table->index('brand_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_pools');
    }
};
