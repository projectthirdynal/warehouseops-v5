<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────────
        // Reference tables
        // ──────────────────────────────────────────────────────────────
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('abbreviation', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('abbreviation');
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name', 60);
            $table->string('symbol', 6);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->decimal('rate', 14, 6);
            $table->date('rate_date');
            $table->string('source', 30)->default('manual');
            $table->timestamps();
            $table->unique(['from_currency', 'to_currency', 'rate_date']);
            $table->index('rate_date');
        });

        // ──────────────────────────────────────────────────────────────
        // Warehouses + locations
        // ──────────────────────────────────────────────────────────────
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index('is_active');
        });

        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name')->nullable();
            $table->string('type', 20)->default('SHELF'); // BIN, SHELF, ZONE
            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['warehouse_id', 'code']);
            $table->index(['warehouse_id', 'is_active']);
        });

        // ──────────────────────────────────────────────────────────────
        // Extend products
        // ──────────────────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 60)->nullable()->after('sku');
            $table->string('qr_code', 120)->nullable()->after('barcode');
            $table->foreignId('uom_id')->nullable()->after('qr_code')->constrained('units_of_measure')->nullOnDelete();
            $table->integer('min_stock_level')->default(0)->after('cost_price');
            $table->integer('max_stock_level')->nullable()->after('min_stock_level');
            $table->boolean('expiry_tracking')->default(false)->after('max_stock_level');
            $table->index('barcode');
        });

        // Extend product_stocks (location-aware) and inventory_movements
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('variant_id')->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->after('warehouse_id')->constrained('warehouse_locations')->nullOnDelete();
            $table->index(['warehouse_id', 'location_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('variant_id')->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->after('warehouse_id')->constrained('warehouse_locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->after('location_id')->constrained('warehouse_locations')->nullOnDelete();
            $table->string('batch_number', 60)->nullable()->after('notes');
            $table->date('expiry_date')->nullable()->after('batch_number');
            $table->foreignId('approved_by')->nullable()->after('performed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        // ──────────────────────────────────────────────────────────────
        // Supplies (consumables)
        // ──────────────────────────────────────────────────────────────
        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60)->unique();
            $table->string('name');
            $table->string('category', 60)->nullable();
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('cost_price', 12, 4)->default(0);
            $table->integer('min_stock_level')->default(0);
            $table->integer('reorder_point')->default(10);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('is_active');
            $table->index('category');
        });

        Schema::create('supply_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->integer('current_stock')->default(0);
            $table->integer('reserved_stock')->default(0);
            $table->integer('reorder_point')->default(10);
            $table->timestamp('last_restock_at')->nullable();
            $table->timestamps();
            $table->unique(['supply_id', 'warehouse_id', 'location_id'], 'supply_stocks_uniq');
        });

        Schema::create('supply_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // STOCK_IN, STOCK_OUT, ADJUSTMENT, RETURN
            $table->integer('quantity');
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('batch_number', 60)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supply_id', 'created_at']);
            $table->index('type');
            $table->index(['reference_type', 'reference_id']);
        });

        // ──────────────────────────────────────────────────────────────
        // Stock adjustments + audit sessions
        // ──────────────────────────────────────────────────────────────
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supply_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('reason_code', 50);
            $table->text('reason_notes')->nullable();
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->integer('variance');
            $table->string('status', 20)->default('PENDING'); // PENDING, APPROVED, REJECTED
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('stock_audit_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('name');
            $table->string('status', 20)->default('OPEN'); // OPEN, COUNTING, FINALIZED
            $table->foreignId('started_by')->constrained('users');
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finalized_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('stock_audit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('stock_audit_sessions')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supply_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->integer('system_qty');
            $table->integer('counted_qty')->nullable();
            $table->integer('variance')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        // ──────────────────────────────────────────────────────────────
        // FIFO cost lots
        // ──────────────────────────────────────────────────────────────
        Schema::create('stock_cost_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->unsignedBigInteger('grn_item_id')->nullable();
            $table->decimal('quantity_received', 12, 4);
            $table->decimal('quantity_remaining', 12, 4);
            $table->decimal('unit_cost', 12, 4);
            $table->char('currency_code', 3)->default('PHP');
            $table->decimal('exchange_rate', 14, 6)->default(1.0);
            $table->timestamp('received_at');
            $table->date('expiry_date')->nullable();
            $table->string('batch_number', 60)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'variant_id', 'warehouse_id', 'received_at'], 'cost_lots_fifo_idx');
            $table->index(['expiry_date'], 'cost_lots_expiry_idx');
        });

        // ──────────────────────────────────────────────────────────────
        // Stock reservations
        // ──────────────────────────────────────────────────────────────
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantity');
            $table->string('reference_type', 60);
            $table->unsignedBigInteger('reference_id');
            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at');
            $table->timestamp('expires_at');
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, CONSUMED, RELEASED, EXPIRED
            $table->timestamp('released_at')->nullable();
            $table->string('released_reason', 60)->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        // ──────────────────────────────────────────────────────────────
        // Suppliers
        // ──────────────────────────────────────────────────────────────
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('payment_terms', 60)->nullable(); // NET_30, COD, etc.
            $table->integer('lead_time_days')->default(7);
            $table->boolean('is_active')->default(true);
            $table->string('qbo_vendor_id', 60)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('is_active');
        });

        // ──────────────────────────────────────────────────────────────
        // Purchase Requests
        // ──────────────────────────────────────────────────────────────
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('pr_number', 30)->unique();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('department', 60)->nullable();
            $table->text('reason')->nullable();
            $table->string('priority', 20)->default('NORMAL'); // LOW, NORMAL, URGENT
            $table->date('needed_by_date')->nullable();
            $table->string('status', 30)->default('DRAFT'); // DRAFT, SUBMITTED, APPROVED, CONVERTED, REJECTED, CANCELLED
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->decimal('estimated_total', 14, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
            $table->index('requested_by');
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pr_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supply_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->integer('quantity_requested');
            $table->decimal('unit_price_estimate', 12, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ──────────────────────────────────────────────────────────────
        // Purchase Orders
        // ──────────────────────────────────────────────────────────────
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 30)->unique();
            $table->foreignId('pr_id')->nullable()->constrained('purchase_requests')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('payment_terms', 60)->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->string('status', 30)->default('DRAFT'); // DRAFT, SENT, PARTIALLY_RECEIVED, RECEIVED, CANCELLED
            $table->char('currency_code', 3)->default('PHP');
            $table->decimal('exchange_rate', 14, 6)->default(1.0);
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('qbo_po_id', 60)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
            $table->index('supplier_id');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supply_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->integer('quantity_ordered');
            $table->integer('quantity_received')->default(0);
            $table->decimal('unit_price', 12, 4);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('po_id');
        });

        // ──────────────────────────────────────────────────────────────
        // Receiving Reports (GRN)
        // ──────────────────────────────────────────────────────────────
        Schema::create('receiving_reports', function (Blueprint $table) {
            $table->id();
            $table->string('grn_number', 30)->unique();
            $table->foreignId('po_id')->constrained('purchase_orders');
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->foreignId('received_by')->constrained('users');
            $table->timestamp('received_at');
            $table->decimal('exchange_rate', 14, 6)->default(1.0);
            $table->date('exchange_rate_date')->nullable();
            $table->string('status', 20)->default('DRAFT'); // DRAFT, CONFIRMED
            $table->text('notes')->nullable();
            $table->text('discrepancy_notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('po_id');
        });

        Schema::create('receiving_report_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grn_id')->constrained('receiving_reports')->cascadeOnDelete();
            $table->foreignId('po_item_id')->constrained('purchase_order_items');
            $table->integer('quantity_received');
            $table->integer('quantity_rejected')->default(0);
            $table->string('rejection_reason', 120)->nullable();
            $table->string('condition', 20)->default('GOOD'); // GOOD, DAMAGED, EXPIRED
            $table->string('batch_number', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('grn_id');
        });

        // Now that receiving_report_items exists, add the FK on stock_cost_lots
        Schema::table('stock_cost_lots', function (Blueprint $table) {
            $table->foreign('grn_item_id')->references('id')->on('receiving_report_items')->nullOnDelete();
        });

        // ──────────────────────────────────────────────────────────────
        // Approval rules
        // ──────────────────────────────────────────────────────────────
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->string('module', 20); // PR, PO
            $table->string('role_required', 30);
            $table->decimal('min_amount', 14, 2)->default(0);
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['module', 'is_active']);
        });

        // ──────────────────────────────────────────────────────────────
        // Finance settings + COGS entries + QBO sync queue
        // ──────────────────────────────────────────────────────────────
        Schema::create('finance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->json('value');
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('locked_trigger_reference', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('cogs_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedBigInteger('waybill_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreignId('cost_lot_id')->nullable()->constrained('stock_cost_lots')->nullOnDelete();
            $table->string('method', 20)->default('FIFO');
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('total_cost', 14, 4);
            $table->char('currency_code', 3)->default('PHP');
            $table->decimal('exchange_rate', 14, 6)->default(1.0);
            $table->timestamp('recorded_at');
            $table->timestamp('synced_to_qbo_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'recorded_at']);
            $table->index('waybill_id');
        });

        Schema::create('qbo_connections', function (Blueprint $table) {
            $table->id();
            $table->string('realm_id', 60);
            $table->text('access_token'); // encrypted via cast
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->string('environment', 20)->default('SANDBOX');
            $table->foreignId('connected_by')->constrained('users');
            $table->timestamp('connected_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('qbo_sync_queue', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->string('operation', 20);
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 20)->default('PENDING');
            $table->string('qbo_id', 60)->nullable();
            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
            $table->index('status');
        });

        Schema::create('qbo_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('mapping_key', 60)->unique();
            $table->string('qbo_account_id', 60);
            $table->string('qbo_account_name')->nullable();
            $table->foreignId('mapped_by')->constrained('users');
            $table->timestamps();
        });

        // ──────────────────────────────────────────────────────────────
        // Seed default reference data
        // ──────────────────────────────────────────────────────────────
        DB::table('currencies')->insert([
            ['code' => 'PHP', 'name' => 'Philippine Peso',  'symbol' => '₱', 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name' => 'US Dollar',         'symbol' => '$', 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'CNY', 'name' => 'Chinese Yuan',      'symbol' => '¥', 'decimal_places' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('units_of_measure')->insert([
            ['name' => 'Pieces', 'abbreviation' => 'pcs',  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Boxes',  'abbreviation' => 'box',  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Cases',  'abbreviation' => 'case', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pack',   'abbreviation' => 'pack', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Kg',     'abbreviation' => 'kg',   'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Liter',  'abbreviation' => 'L',    'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('warehouses')->insert([
            'name'       => 'Main Warehouse',
            'code'       => 'MAIN',
            'address'    => null,
            'is_active'  => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('finance_settings')->insert([
            ['key' => 'cogs_method',              'value' => json_encode(['method' => 'FIFO']),     'created_at' => now(), 'updated_at' => now()],
            ['key' => 'default_currency',         'value' => json_encode(['code' => 'PHP']),         'created_at' => now(), 'updated_at' => now()],
            ['key' => 'fiscal_year_start_month',  'value' => json_encode(['month' => 1]),            'created_at' => now(), 'updated_at' => now()],
            ['key' => 'reservation_expiry_cart',  'value' => json_encode(['minutes' => 30]),         'created_at' => now(), 'updated_at' => now()],
            ['key' => 'reservation_expiry_order', 'value' => json_encode(['hours' => 24]),           'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pr_auto_approve_under',    'value' => json_encode(['amount' => 5000]),        'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::table('stock_cost_lots', function (Blueprint $table) {
            $table->dropForeign(['grn_item_id']);
        });

        Schema::dropIfExists('qbo_account_mappings');
        Schema::dropIfExists('qbo_sync_queue');
        Schema::dropIfExists('qbo_connections');
        Schema::dropIfExists('cogs_entries');
        Schema::dropIfExists('finance_settings');
        Schema::dropIfExists('approval_rules');
        Schema::dropIfExists('receiving_report_items');
        Schema::dropIfExists('receiving_reports');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_cost_lots');
        Schema::dropIfExists('stock_audit_items');
        Schema::dropIfExists('stock_audit_sessions');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('supply_movements');
        Schema::dropIfExists('supply_stocks');
        Schema::dropIfExists('supplies');

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropForeign(['location_id']);
            $table->dropForeign(['to_location_id']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['warehouse_id', 'location_id', 'to_location_id', 'batch_number', 'expiry_date', 'approved_by', 'approved_at']);
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropForeign(['location_id']);
            $table->dropColumn(['warehouse_id', 'location_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['uom_id']);
            $table->dropColumn(['barcode', 'qr_code', 'uom_id', 'min_stock_level', 'max_stock_level', 'expiry_tracking']);
        });

        Schema::dropIfExists('warehouse_locations');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('units_of_measure');
    }
};
