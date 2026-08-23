<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Direct link to the source waybill for age-based eligibility
            // (waybill.delivered_at is the authoritative date, not lead.created_at)
            $table->unsignedBigInteger('source_waybill_id')->nullable()->after('source');
            $table->foreign('source_waybill_id', 'leads_source_waybill_id_foreign')
                ->references('id')
                ->on('waybills')
                ->nullOnDelete();

            // Link to address_mappings for region/province/city filtering
            $table->foreignId('address_mapping_id')->nullable()
                ->after('source_waybill_id')
                ->constrained('address_mappings')
                ->nullOnDelete();

            $table->index(['source_waybill_id']);
            $table->index(['address_mapping_id']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['address_mapping_id']);
            $table->dropForeign('leads_source_waybill_id_foreign');
            $table->dropIndex(['source_waybill_id']);
            $table->dropIndex(['address_mapping_id']);
            $table->dropColumn(['source_waybill_id', 'address_mapping_id']);
        });
    }
};
