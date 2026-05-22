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
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasIndex($table, $indexName)) {
            Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
                $table->index($columns, $indexName);
            });
        }
    }

    private function dropIndexIfExists(string $table, string $indexName, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (Schema::hasIndex($table, $indexName)) {
            Schema::table($table, function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            ['waybills', 'waybills_receiver_name_index', ['receiver_name']],
            ['waybills', 'waybills_status_delivered_at_index', ['status', 'delivered_at']],
            ['waybills', 'waybills_status_returned_at_index', ['status', 'returned_at']],
            ['waybills', 'waybills_upload_id_status_index', ['upload_id', 'status']],
            ['leads', 'leads_assigned_to_pool_status_index', ['assigned_to', 'pool_status']],
            ['leads', 'leads_pool_status_product_name_index', ['pool_status', 'product_name']],
            ['leads', 'leads_sales_status_index', ['sales_status']],
            ['leads', 'leads_customer_id_index', ['customer_id']],
            ['leads', 'leads_pool_status_cooldown_until_index', ['pool_status', 'cooldown_until']],
            ['lead_cycles', 'lead_cycles_assigned_agent_id_status_index', ['assigned_agent_id', 'status']],
            ['lead_cycles', 'lead_cycles_assigned_agent_id_opened_at_index', ['assigned_agent_id', 'opened_at']],
            ['orders', 'orders_lead_id_index', ['lead_id']],
            ['orders', 'orders_customer_id_index', ['customer_id']],
            ['orders', 'orders_product_id_index', ['product_id']],
            ['orders', 'orders_status_created_at_index', ['status', 'created_at']],
            ['agent_commissions', 'agent_commissions_agent_id_earned_at_index', ['agent_id', 'earned_at']],
        ] as [$table, $indexName, $columns]) {
            $this->dropIndexIfExists($table, $indexName, $columns);
        }
    }
};
