<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for 100+ concurrent users.
 * Targets the most common query patterns:
 * - Agent portal lead lookups (assigned_to + pool_status)
 * - Waybill tracking search (receiver_name, receiver_phone)
 * - Dashboard stats (status counts, date filters)
 * - Lead pool distribution (pool_status + product_name)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Waybills — search by name/phone (agent tracking)
        Schema::table('waybills', function (Blueprint $table) {
            $table->index(['receiver_name']);
            $table->index(['courier_provider', 'status']);
            $table->index(['status', 'delivered_at']);
            $table->index(['status', 'returned_at']);
            $table->index(['upload_id', 'status']);
        });

        // Leads — agent portal + distribution queries
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['assigned_to', 'pool_status']);
            $table->index(['pool_status', 'product_name']);
            $table->index(['sales_status']);
            $table->index(['customer_id']);
            $table->index(['pool_status', 'cooldown_until']);
        });

        // Lead cycles — agent call history
        Schema::table('lead_cycles', function (Blueprint $table) {
            $table->index(['assigned_agent_id', 'status']);
            $table->index(['assigned_agent_id', 'opened_at']);
        });

        // Orders — pipeline queries
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['lead_id']);
            $table->index(['customer_id']);
            $table->index(['product_id']);
            $table->index(['status', 'created_at']);
        });

        // Agent commissions — finance queries
        Schema::table('agent_commissions', function (Blueprint $table) {
            $table->index(['agent_id', 'earned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('waybills', function (Blueprint $table) {
            $table->dropIndex(['receiver_name']);
            $table->dropIndex(['courier_provider', 'status']);
            $table->dropIndex(['status', 'delivered_at']);
            $table->dropIndex(['status', 'returned_at']);
            $table->dropIndex(['upload_id', 'status']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['assigned_to', 'pool_status']);
            $table->dropIndex(['pool_status', 'product_name']);
            $table->dropIndex(['sales_status']);
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['pool_status', 'cooldown_until']);
        });

        Schema::table('lead_cycles', function (Blueprint $table) {
            $table->dropIndex(['assigned_agent_id', 'status']);
            $table->dropIndex(['assigned_agent_id', 'opened_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['lead_id']);
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['product_id']);
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('agent_commissions', function (Blueprint $table) {
            $table->dropIndex(['agent_id', 'earned_at']);
        });
    }
};
