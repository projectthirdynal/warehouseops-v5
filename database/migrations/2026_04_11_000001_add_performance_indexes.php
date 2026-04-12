<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $this->addIndexIfNotExists('waybills', 'waybills_receiver_name_index', ['receiver_name']);
        $this->addIndexIfNotExists('waybills', 'waybills_status_delivered_at_index', ['status', 'delivered_at']);
        $this->addIndexIfNotExists('waybills', 'waybills_status_returned_at_index', ['status', 'returned_at']);
        $this->addIndexIfNotExists('waybills', 'waybills_upload_id_status_index', ['upload_id', 'status']);

        $this->addIndexIfNotExists('leads', 'leads_assigned_to_pool_status_index', ['assigned_to', 'pool_status']);
        $this->addIndexIfNotExists('leads', 'leads_pool_status_product_name_index', ['pool_status', 'product_name']);
        $this->addIndexIfNotExists('leads', 'leads_sales_status_index', ['sales_status']);
        $this->addIndexIfNotExists('leads', 'leads_customer_id_index', ['customer_id']);
        $this->addIndexIfNotExists('leads', 'leads_pool_status_cooldown_until_index', ['pool_status', 'cooldown_until']);

        $this->addIndexIfNotExists('lead_cycles', 'lead_cycles_assigned_agent_id_status_index', ['assigned_agent_id', 'status']);
        $this->addIndexIfNotExists('lead_cycles', 'lead_cycles_assigned_agent_id_opened_at_index', ['assigned_agent_id', 'opened_at']);

        $this->addIndexIfNotExists('orders', 'orders_lead_id_index', ['lead_id']);
        $this->addIndexIfNotExists('orders', 'orders_customer_id_index', ['customer_id']);
        $this->addIndexIfNotExists('orders', 'orders_product_id_index', ['product_id']);
        $this->addIndexIfNotExists('orders', 'orders_status_created_at_index', ['status', 'created_at']);

        $this->addIndexIfNotExists('agent_commissions', 'agent_commissions_agent_id_earned_at_index', ['agent_id', 'earned_at']);
    }

    private function addIndexIfNotExists(string $table, string $indexName, array $columns): void
    {
        $exists = DB::select("SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]);

        if (empty($exists)) {
            Schema::table($table, function (Blueprint $table) use ($columns) {
                $table->index($columns);
            });
        }
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
