<?php

namespace Database\Seeders;

use Modules\Couriers\Models\CourierProvider;
use App\Domain\Finance\Models\CodSettlement;
use App\Domain\Finance\Models\CommissionRule;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\UnitOfMeasure;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseLocation;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Procurement\Enums\PoStatus;
use App\Domain\Procurement\Enums\PrStatus;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\PurchaseRequestItem;
use App\Domain\Procurement\Models\Supplier;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use App\Domain\Product\Models\ProductVariant;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\CustomerIdentity;
use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\Message;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Lead;
use App\Models\SiteSetting;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Database\Seeder;

class SystemSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $superadmin = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Super Admin', 'password' => bcrypt('!Admin00'), 'role' => 'superadmin', 'is_active' => true, 'email_verified_at' => now()]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@tecc.ph'],
            ['name' => 'Admin User', 'password' => bcrypt('password'), 'role' => 'admin', 'is_active' => true]
        );
        $supervisor = User::firstOrCreate(
            ['email' => 'supervisor@tecc.ph'],
            ['name' => 'Maria Supervisor', 'password' => bcrypt('password'), 'role' => 'supervisor', 'is_active' => true]
        );
        $agent = User::firstOrCreate(
            ['email' => 'agent@tecc.ph'],
            ['name' => 'Juan Agent', 'password' => bcrypt('password'), 'role' => 'agent', 'is_active' => true]
        );
        $warehouseUser = User::firstOrCreate(
            ['email' => 'warehouse@tecc.ph'],
            ['name' => 'Pedro Warehouse', 'password' => bcrypt('password'), 'role' => 'warehouse', 'is_active' => true]
        );
        $financeUser = User::firstOrCreate(
            ['email' => 'finance@tecc.ph'],
            ['name' => 'Ana Finance', 'password' => bcrypt('password'), 'role' => 'finance', 'is_active' => true]
        );

        $this->seedShop($admin, $supervisor, $agent);
        $this->seedOperations($admin, $agent, $warehouseUser);
        $this->seedProcurement($admin, $warehouseUser);
        $this->seedCommercial($admin, $financeUser);
        $this->seedCrm($admin, $agent);
        $this->seedLogistics($admin, $agent);
        $this->seedSystem($admin);
    }

    private function seedShop(User $admin, User $supervisor, User $agent): void
    {
        $fbPage = FacebookPage::firstOrCreate(
            ['page_id' => 'fb_page_001'],
            [
                'page_name' => 'TECC Official Store',
                'connected_status' => 'connected',
                'webhook_status' => 'subscribed',
                'connected_by' => $admin->id,
                'default_courier' => 'JNT',
                'last_sync_at' => now()->subHours(2),
            ]
        );

        FacebookPage::firstOrCreate(
            ['page_id' => 'fb_page_002'],
            [
                'page_name' => 'TECC Promo Deals',
                'connected_status' => 'connected',
                'webhook_status' => 'subscribed',
                'connected_by' => $admin->id,
                'default_courier' => 'LBC',
                'last_sync_at' => now()->subHours(5),
            ]
        );

        $c1 = Customer::firstOrCreate(
            ['phone' => '09175551234'],
            [
                'name' => 'Maria Santos',
                'normalized_phone' => '639175551234',
                'facebook_name' => 'Maria Santos',
                'canonical_address' => '123 Mabini St, Brgy San Roque, Quezon City, Metro Manila',
                'barangay' => 'San Roque',
                'city_municipality' => 'Quezon City',
                'province' => 'Metro Manila',
                'region' => 'NCR',
                'landmark' => 'Near Mercury Drug',
                'total_orders' => 5,
                'successful_orders' => 4,
                'returned_orders' => 1,
                'success_rate' => 80.00,
                'total_revenue' => 12500.00,
                'average_order_value' => 2500.00,
                'preferred_courier' => 'JNT',
                'payment_method' => 'COD',
                'risk_level' => 'LOW',
                'tags' => ['VIP', 'Repeat Buyer'],
            ]
        );

        Customer::firstOrCreate(
            ['phone' => '09185555678'],
            [
                'name' => 'Juan Dela Cruz',
                'normalized_phone' => '639185555678',
                'facebook_name' => 'Juan DC',
                'canonical_address' => '456 Rizal Ave, Brgy Malimban, San Fernando, Pampanga',
                'barangay' => 'Malimban',
                'city_municipality' => 'San Fernando',
                'province' => 'Pampanga',
                'region' => 'Region III',
                'landmark' => 'Beside Jollibee',
                'total_orders' => 2,
                'successful_orders' => 1,
                'returned_orders' => 1,
                'success_rate' => 50.00,
                'total_revenue' => 3200.00,
                'average_order_value' => 1600.00,
                'preferred_courier' => 'LBC',
                'payment_method' => 'COD',
                'risk_level' => 'MEDIUM',
                'tags' => ['New Customer'],
            ]
        );

        CustomerNote::firstOrCreate(
            ['customer_id' => $c1->id, 'body' => 'Customer prefers afternoon delivery. Always call before dispatch.'],
            ['user_id' => $agent->id, 'note_type' => 'agent_note', 'pinned_until' => now()->addDays(30)]
        );

        $identity = CustomerIdentity::firstOrCreate(
            ['facebook_page_id' => $fbPage->id, 'customer_id' => $c1->id, 'provider_user_id' => 'fb_user_001'],
            ['provider' => 'facebook', 'display_name' => 'Maria Santos', 'phone_detected' => '09175551234']
        );

        $conv = Conversation::firstOrCreate(
            ['facebook_page_id' => $fbPage->id, 'customer_id' => $c1->id, 'thread_key' => 'thread_001'],
            [
                'customer_identity_id' => $identity->id,
                'assigned_agent_id' => $agent->id,
                'channel' => 'messenger',
                'status' => Conversation::STATUS_ASSIGNED,
                'priority' => 'normal',
                'last_message_preview' => 'Hi, when will my order arrive?',
                'last_message_at' => now()->subMinutes(30),
                'unread_count' => 1,
                'first_response_at' => now()->subHours(2),
            ]
        );

        Message::firstOrCreate(
            ['conversation_id' => $conv->id, 'external_message_id' => 'msg_001'],
            [
                'facebook_page_id' => $fbPage->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'body' => 'Hi, when will my order arrive?',
                'sent_at' => now()->subMinutes(30),
                'send_status' => 'delivered',
            ]
        );

        Message::firstOrCreate(
            ['conversation_id' => $conv->id, 'external_message_id' => 'msg_002'],
            [
                'facebook_page_id' => $fbPage->id,
                'sent_by' => $agent->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => 'Hi Maria! Your order is out for delivery today via J&T. Expected arrival 2-4 PM.',
                'sent_at' => now()->subMinutes(20),
                'send_status' => 'sent',
            ]
        );
    }

    private function seedOperations(User $admin, User $agent, User $warehouseUser): void
    {
        $p1 = Product::firstOrCreate(
            ['sku' => 'TECC-PHONE-001'],
            [
                'name' => 'Smartphone X Pro 128GB', 'brand' => 'TechBrand',
                'category' => 'Electronics', 'barcode' => '4800123456789',
                'selling_price' => 8500.00, 'cost_price' => 6200.00,
                'weight_grams' => 350, 'is_active' => true, 'requires_qa' => true,
                'description' => 'Smartphone X Pro with 128GB storage, 6.5" display, 5000mAh battery.',
            ]
        );
        $p2 = Product::firstOrCreate(
            ['sku' => 'TECC-CASE-001'],
            [
                'name' => 'Premium Phone Case X Pro', 'brand' => 'TechBrand',
                'category' => 'Accessories', 'barcode' => '4800123456790',
                'selling_price' => 450.00, 'cost_price' => 180.00,
                'weight_grams' => 80, 'is_active' => true, 'requires_qa' => false,
                'description' => 'Premium silicone phone case for Smartphone X Pro.',
            ]
        );
        ProductVariant::firstOrCreate(
            ['product_id' => $p1->id, 'sku' => 'TECC-PHONE-001-BLK'],
            ['variant_name' => 'Black', 'selling_price' => 8500.00, 'weight_grams' => 350, 'is_active' => true]
        );
        $wh = Warehouse::firstOrCreate(
            ['code' => 'WH-MAIN'],
            ['name' => 'Main Warehouse - Manila', 'address' => '123 Warehouse Rd, Port Area, Manila',
                'contact_person' => 'Pedro Warehouse', 'contact_phone' => '09190001111',
                'is_active' => true, 'is_default' => true]
        );
        WarehouseLocation::firstOrCreate(
            ['warehouse_id' => $wh->id, 'code' => 'A-01-01'],
            ['name' => 'Aisle A Rack 1 L1', 'type' => 'shelf', 'capacity' => 500, 'is_active' => true]
        );
        ProductStock::firstOrCreate(
            ['product_id' => $p1->id, 'warehouse_id' => $wh->id],
            ['current_stock' => 120, 'reserved_stock' => 15, 'reorder_point' => 30,
                'last_restock_at' => now()->subDays(7), 'last_movement_at' => now()->subHours(3)]
        );
        ProductStock::firstOrCreate(
            ['product_id' => $p2->id, 'warehouse_id' => $wh->id],
            ['current_stock' => 25, 'reserved_stock' => 5, 'reorder_point' => 20,
                'last_restock_at' => now()->subDays(14), 'last_movement_at' => now()->subDays(2)]
        );
        StockAdjustment::firstOrCreate(
            ['product_id' => $p2->id, 'warehouse_id' => $wh->id, 'reason_code' => 'DAMAGE'],
            ['quantity_before' => 30, 'quantity_after' => 25, 'variance' => -5, 'status' => 'approved',
                'submitted_by' => $warehouseUser->id, 'approved_by' => $admin->id,
                'approved_at' => now()->subDays(2), 'reason_notes' => '5 units damaged during handling']
        );
        $cust = Customer::where('phone', '09175551234')->first();
        Order::firstOrCreate(
            ['order_number' => 'ORD-'.now()->format('Ymd').'-0001'],
            ['customer_id' => $cust?->id, 'product_id' => $p1->id, 'assigned_agent_id' => $agent->id,
                'status' => OrderStatus::DISPATCHED, 'courier_code' => 'JNT', 'quantity' => 1,
                'unit_price' => 8500.00, 'total_amount' => 8500.00, 'cod_amount' => 8500.00,
                'shipping_cost' => 150.00, 'receiver_name' => 'Maria Santos',
                'receiver_phone' => '09175551234', 'receiver_address' => '123 Mabini St, Quezon City',
                'city' => 'Quezon City', 'state' => 'Metro Manila', 'barangay' => 'San Roque',
                'source_channel' => 'facebook', 'confirmed_at' => now()->subDays(2),
                'dispatched_at' => now()->subHours(6)]
        );
        Waybill::firstOrCreate(
            ['waybill_number' => 'WB-2026-00123'],
            ['creator_code' => 'JNT', 'status' => 'dispatched', 'receiver_name' => 'Maria Santos',
                'receiver_phone' => '09175551234', 'receiver_address' => '123 Mabini St, Quezon City',
                'city' => 'Quezon City', 'state' => 'Metro Manila',
                'item_name' => 'Smartphone X Pro 128GB', 'item_qty' => 1, 'item_value' => 8500.00,
                'amount' => 8500.00, 'cod_amount' => 8500.00, 'payment_method' => 'COD',
                'shipping_cost' => 150.00, 'courier_provider' => 'JNT', 'express_type' => 'STANDARD',
                'sender_name' => 'TECC Official Store', 'sender_phone' => '09190001234',
                'sender_province' => 'Metro Manila', 'sender_city' => 'Manila',
                'dispatched_at' => now()->subHours(6)]
        );
    }

    private function seedProcurement(User $admin, User $warehouseUser): void
    {
        $supplier = Supplier::firstOrCreate(
            ['code' => 'SUP-001'],
            ['name' => 'TechBrand Distributors Inc.', 'contact_person' => 'Lisa Chen',
                'email' => 'orders@techbrand-dist.com', 'phone' => '09220003333',
                'address' => '8F Corporate Tower, Makati City',
                'payment_terms' => 'NET_30', 'lead_time_days' => 7, 'is_active' => true]
        );
        $uom = UnitOfMeasure::firstOrCreate(
            ['abbreviation' => 'pc'],
            ['name' => 'Piece', 'is_active' => true]
        );
        $wh = Warehouse::where('code', 'WH-MAIN')->first();
        $product = Product::where('sku', 'TECC-PHONE-001')->first();

        $pr = PurchaseRequest::firstOrCreate(
            ['pr_number' => 'PR-'.now()->format('Ymd').'-0001'],
            ['requested_by' => $warehouseUser->id, 'department' => 'Operations',
                'reason' => 'Restocking for Q3 demand', 'priority' => 'high',
                'needed_by_date' => now()->addDays(10), 'status' => PrStatus::APPROVED,
                'approved_by' => $admin->id, 'approved_at' => now()->subDays(3),
                'estimated_total' => 310000.00]
        );
        PurchaseRequestItem::firstOrCreate(
            ['pr_id' => $pr->id, 'product_id' => $product?->id],
            ['uom_id' => $uom->id, 'quantity_requested' => 50,
                'unit_price_estimate' => 6200.00, 'notes' => 'Black variant preferred']
        );

        $po = PurchaseOrder::firstOrCreate(
            ['po_number' => 'PO-'.now()->format('Ymd').'-0001'],
            ['pr_id' => $pr->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $wh?->id,
                'payment_terms' => 'NET_30', 'expected_delivery_date' => now()->addDays(7),
                'status' => PoStatus::SENT, 'currency_code' => 'PHP',
                'exchange_rate' => 1.000000, 'subtotal' => 310000.00,
                'tax_amount' => 0.00, 'total_amount' => 310000.00,
                'approved_by' => $admin->id, 'approved_at' => now()->subDays(2),
                'sent_at' => now()->subDays(1), 'created_by' => $admin->id]
        );
        PurchaseOrderItem::firstOrCreate(
            ['po_id' => $po->id, 'product_id' => $product?->id],
            ['uom_id' => $uom->id, 'quantity_ordered' => 50, 'quantity_received' => 0,
                'unit_price' => 6200.00, 'tax_rate' => 0.00, 'line_total' => 310000.00]
        );
    }

    private function seedCommercial(User $admin, User $financeUser): void
    {
        $tp = ThirdParty::firstOrCreate(
            ['ref' => 'TP-0001'],
            ['name' => 'TechBrand Distributors Inc.', 'type' => ThirdParty::TYPE_SUPPLIER,
                'email' => 'orders@techbrand-dist.com', 'phone' => '09220003333',
                'status' => ThirdParty::STATUS_ACTIVE, 'payment_terms' => 'NET_30',
                'credit_limit' => 500000.00, 'address_line1' => '8F Corporate Tower, Makati City',
                'city' => 'Makati City', 'state_province' => 'Metro Manila',
                'country' => 'Philippines', 'risk_level' => ThirdParty::RISK_LOW,
                'created_by' => $admin->id]
        );

        $inv = Invoice::firstOrCreate(
            ['ref' => 'INV-'.now()->year.'-00001'],
            ['type' => 'standard', 'status' => 'SENT', 'third_party_id' => $tp->id,
                'client_name' => 'TechBrand Distributors Inc.',
                'client_email' => 'orders@techbrand-dist.com',
                'date_invoice' => now()->subDays(5), 'date_due' => now()->addDays(25),
                'payment_terms' => 'NET_30', 'currency' => 'PHP',
                'subtotal' => 310000.00, 'discount_amount' => 0.00, 'tax_rate' => 0.00,
                'tax_amount' => 0.00, 'shipping_amount' => 0.00, 'total_amount' => 310000.00,
                'amount_paid' => 0.00, 'amount_due' => 310000.00, 'created_by' => $financeUser->id]
        );
        InvoiceLine::firstOrCreate(
            ['invoice_id' => $inv->id, 'description' => 'Smartphone X Pro 128GB x50 units'],
            ['qty' => 50, 'unit_price' => 6200.00, 'total_ht' => 310000.00, 'total_ttc' => 310000.00]
        );

        CommissionRule::firstOrCreate(
            ['product_id' => null],
            ['rate_type' => 'PERCENTAGE', 'rate_value' => 3.00,
                'min_sale_amount' => 500.00, 'is_active' => true]
        );

        CodSettlement::firstOrCreate(
            ['reference_number' => 'COD-2026-07-001'],
            ['courier_code' => 'JNT', 'period_start' => now()->subDays(15),
                'period_end' => now()->subDays(1), 'total_cod_collected' => 42500.00,
                'courier_fee' => 1275.00, 'net_amount' => 41225.00,
                'order_count' => 5, 'status' => 'PENDING']
        );

        FinancialTransaction::firstOrCreate(
            ['description' => 'COD Settlement - JNT - July Batch 1'],
            ['type' => 'income', 'amount' => 41225.00,
                'reference_type' => 'App\\Domain\\Finance\\Models\\CodSettlement',
                'recorded_by' => $financeUser->id, 'transaction_date' => now()->subDay()]
        );
    }

    private function seedCrm(User $admin, User $agent): void
    {
        Lead::firstOrCreate(
            ['phone' => '09195558888', 'name' => 'Cristina Reyes'],
            ['address' => '789 Aurora Blvd, Cubao, Quezon City',
                'city' => 'Quezon City', 'state' => 'Metro Manila',
                'status' => 'NEW', 'sales_status' => 'NEW', 'source' => 'FACEBOOK',
                'assigned_to' => $agent->id, 'product_name' => 'Smartphone X Pro 128GB',
                'product_brand' => 'TechBrand', 'amount' => 8500.00,
                'total_cycles' => 1, 'quality_score' => 85]
        );

        $campaign = SmsCampaign::firstOrCreate(
            ['name' => 'July Flash Sale Blast'],
            ['message' => 'Hi! Get 15% off on Smartphone X Pro this weekend only. Visit TECC Official Store to order now!',
                'type' => 'broadcast', 'status' => 'completed',
                'target_audience' => 'all_customers', 'total_recipients' => 150,
                'sent_count' => 148, 'failed_count' => 2, 'delivered_count' => 145,
                'created_by' => $admin->id, 'started_at' => now()->subDays(3),
                'completed_at' => now()->subDays(3)]
        );

        SmsLog::firstOrCreate(
            ['campaign_id' => $campaign->id, 'phone' => '09175551234'],
            ['message' => $campaign->message, 'status' => 'delivered', 'sent_at' => now()->subDays(3)]
        );
    }

    private function seedLogistics(User $admin, User $agent): void
    {
        CourierProvider::firstOrCreate(
            ['code' => 'JNT'],
            ['name' => 'J&T Express', 'is_active' => true,
                'api_endpoint' => 'https://api.jtexpress.ph/v1']
        );
        CourierProvider::firstOrCreate(
            ['code' => 'LBC'],
            ['name' => 'LBC Express', 'is_active' => true,
                'api_endpoint' => 'https://api.lbcexpress.com/v1']
        );

        Supply::firstOrCreate(
            ['sku' => 'SUP-BOX-SM'],
            ['name' => 'Small Box (20x15x10cm)', 'section' => Supply::SECTION_STOCK,
                'stock_category' => 'MERCHANDISE', 'cost_price' => 12.00,
                'min_stock_level' => 100, 'reorder_point' => 50, 'is_active' => true]
        );

        $wh = Warehouse::where('code', 'WH-MAIN')->first();
        $supply = Supply::where('sku', 'SUP-BOX-SM')->first();
        if ($supply && $wh) {
            SupplyStock::firstOrCreate(
                ['supply_id' => $supply->id, 'warehouse_id' => $wh->id],
                ['current_stock' => 250, 'reserved_stock' => 30, 'reorder_point' => 50,
                    'last_restock_at' => now()->subDays(10)]
            );
        }
    }

    private function seedSystem(User $admin): void
    {
        SiteSetting::firstOrCreate(
            ['key' => 'integration_google_workspace'],
            ['value' => 'connected']
        );
        SiteSetting::firstOrCreate(
            ['key' => 'integration_slack'],
            ['value' => 'connected']
        );
        SiteSetting::firstOrCreate(
            ['key' => 'integration_microsoft_365'],
            ['value' => 'disconnected']
        );
        SiteSetting::firstOrCreate(
            ['key' => 'integration_webhook'],
            ['value' => 'disconnected']
        );
    }
}
