<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capex_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 40)->default('OTHER');
            $table->unsignedTinyInteger('depreciation_years');
            $table->date('purchase_date');
            $table->decimal('acquisition_cost', 15, 4);
            $table->decimal('salvage_value', 15, 4)->default(0);
            $table->decimal('current_book_value', 15, 4)->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('department', 100)->nullable();
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('disposed_at')->nullable();
            $table->string('disposal_reason', 500)->nullable();
            $table->decimal('disposal_value', 15, 4)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('category');
            $table->index('depreciation_years');
        });

        Schema::create('capex_depreciation_schedule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capex_asset_id')->constrained('capex_assets')->cascadeOnDelete();
            $table->unsignedTinyInteger('year');
            $table->unsignedSmallInteger('fiscal_year');
            $table->decimal('opening_book_value', 15, 4);
            $table->decimal('depreciation_amount', 15, 4);
            $table->decimal('closing_book_value', 15, 4);
            $table->date('depreciation_date');
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['capex_asset_id', 'year']);
            $table->index(['capex_asset_id', 'is_posted']);
        });

        Schema::create('capex_asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capex_asset_id')->constrained('capex_assets')->cascadeOnDelete();
            $table->foreignId('assigned_to')->constrained('users');
            $table->string('department', 100)->nullable();
            $table->string('location', 200)->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('assigned_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('capex_asset_id');
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capex_asset_assignments');
        Schema::dropIfExists('capex_depreciation_schedule');
        Schema::dropIfExists('capex_assets');
    }
};
