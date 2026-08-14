<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add reconciliation summary columns to cod_settlements
        Schema::table('cod_settlements', function (Blueprint $table) {
            $table->decimal('expected_cod', 12, 2)->default(0)->after('total_cod_collected');
            $table->decimal('variance', 12, 2)->default(0)->after('net_amount');
            $table->integer('matched_count')->default(0)->after('order_count');
            $table->integer('unmatched_count')->default(0)->after('matched_count');
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete()->after('reconciled_at');
        });

        // COD reconciliation items — one row per matched/unmatched order within a settlement
        Schema::create('cod_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cod_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('waybill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('courier_code');
            $table->string('order_number')->nullable();
            $table->string('waybill_number')->nullable();
            $table->decimal('expected_cod', 10, 2)->default(0);
            $table->decimal('remitted_cod', 10, 2)->default(0);
            $table->decimal('variance', 10, 2)->default(0);
            $table->string('match_status')->default('UNMATCHED'); // MATCHED, UNMATCHED, MANUAL_MATCH, MISMATCH
            $table->string('match_type')->nullable(); // AUTO, MANUAL
            $table->timestamp('matched_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['cod_settlement_id', 'match_status']);
            $table->index(['order_id']);
            $table->index(['waybill_id']);
            $table->index(['courier_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_reconciliation_items');

        Schema::table('cod_settlements', function (Blueprint $table) {
            $table->dropColumn([
                'expected_cod',
                'variance',
                'matched_count',
                'unmatched_count',
                'reconciled_by',
            ]);
        });
    }
};
