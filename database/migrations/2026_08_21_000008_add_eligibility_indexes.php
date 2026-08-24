<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add composite indexes for the LeadEligibilityService query patterns.
 *
 * The primary eligibility query filters on:
 *   - leads.pool_status = AVAILABLE
 *   - leads.source_waybill_id IS NOT NULL
 *   - waybills.delivered_at >= threshold (via join)
 *   - leads.address_mapping_id (via join to address_mappings for region/province)
 *   - leads.product_name ILIKE brand patterns
 *   - customer exclusions (blacklisted, do_not_call, invalid phone)
 *
 * These indexes target the hot paths for counting and filtering eligible leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Composite index for the most common eligibility filter:
        // pool_status + source_waybill_id presence (avoids full scan on 276K rows)
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['pool_status', 'source_waybill_id'], 'leads_pool_status_source_waybill_idx');
        });

        // Index on waybills.delivered_at for the age-range join
        // (delivered_at already has a basic index from the original migration,
        // but we add a composite with status for the DELIVERED+date filter)
        Schema::table('waybills', function (Blueprint $table) {
            $table->index(['status', 'delivered_at'], 'waybills_status_delivered_at_idx');
        });

        // Index on customers for exclusion checks
        Schema::table('customers', function (Blueprint $table) {
            $table->index(['is_blacklisted', 'do_not_call'], 'customers_blacklist_dnc_idx');
        });

        // Add pg_trgm extension for fast ILIKE pattern matching on product_name
        // (used for brand filtering via match_patterns)
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX IF NOT EXISTS leads_product_name_trgm_idx ON leads USING gin (product_name gin_trgm_ops)');
        } catch (Exception $e) {
            // pg_trgm may not be available in SQLite (test env) — skip gracefully
        }
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS leads_product_name_trgm_idx');
        } catch (Exception $e) {
            // ignore
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_pool_status_source_waybill_idx');
        });

        Schema::table('waybills', function (Blueprint $table) {
            $table->dropIndex('waybills_status_delivered_at_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_blacklist_dnc_idx');
        });
    }
};
