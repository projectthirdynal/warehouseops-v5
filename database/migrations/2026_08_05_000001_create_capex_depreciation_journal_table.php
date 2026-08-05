<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capex_depreciation_journal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capex_asset_id')->constrained('capex_assets')->cascadeOnDelete();
            $table->foreignId('depreciation_schedule_id')->nullable()->constrained('capex_depreciation_schedule')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('posting_date');
            $table->decimal('depreciation_amount', 15, 4);
            $table->decimal('accumulated_depreciation', 15, 4)->default(0);
            $table->decimal('book_value_after', 15, 4)->default(0);
            $table->string('debit_account', 100)->default('Depreciation Expense');
            $table->string('credit_account', 100)->default('Accumulated Depreciation');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['capex_asset_id', 'year', 'month']);
            $table->index(['is_posted', 'posting_date']);
            $table->index('capex_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capex_depreciation_journal');
    }
};
