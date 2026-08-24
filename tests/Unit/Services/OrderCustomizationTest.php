<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\SalesStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderFulfillmentService;
use App\Domain\Promo\Enums\PromoType;
use App\Domain\Promo\Models\Promo;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCustomizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_can_be_created_with_custom_address(): void
    {
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Original Name',
            'phone' => '09123456789',
            'address' => 'Original Address',
            'city' => 'Original City',
        ]);

        $service = app(OrderFulfillmentService::class);

        $order = $service->createFromLeadWithCustomization($lead, [
            'quantity' => 3,
            'receiver_name' => 'New Name',
            'receiver_phone' => '09987654321',
            'receiver_address' => 'New Street Address',
            'city' => 'Cebu City',
            'state' => 'Cebu',
            'barangay' => 'Lahug',
            'postal_code' => '6000',
            'landmark' => 'Near JY Square',
            'promo_ids' => [],
        ]);

        $this->assertEquals(3, $order->quantity);
        $this->assertEquals('New Name', $order->receiver_name);
        $this->assertEquals('09987654321', $order->receiver_phone);
        $this->assertEquals('New Street Address', $order->receiver_address);
        $this->assertEquals('Cebu City', $order->city);
        $this->assertEquals('Cebu', $order->state);
        $this->assertEquals('Lahug', $order->barangay);
        $this->assertEquals('6000', $order->postal_code);
        $this->assertEquals('Near JY Square', $order->landmark);
    }

    public function test_order_with_promos_creates_freebie_line_items(): void
    {
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 100.00,
        ]);

        $promo = Promo::factory()->create([
            'type' => PromoType::FREEBIE,
            'free_item_name' => 'Free Sample',
            'free_quantity' => 1,
            'is_active' => true,
        ]);

        $service = app(OrderFulfillmentService::class);

        $order = $service->createFromLeadWithCustomization($lead, [
            'quantity' => 1,
            'promo_ids' => [$promo->id],
        ]);

        // Main item should exist
        $mainItem = ShopOrderItem::where('order_id', $order->id)
            ->where('metadata->type', 'main')
            ->first();
        $this->assertNotNull($mainItem);

        // Freebie item should exist with zero price
        $freebieItem = ShopOrderItem::where('order_id', $order->id)
            ->where('unit_price', 0)
            ->first();
        $this->assertNotNull($freebieItem);
        $this->assertEquals('Free Sample', $freebieItem->product_name);
    }

    public function test_order_with_discount_promo_updates_total(): void
    {
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 200.00,
        ]);

        $promo = Promo::factory()->create([
            'type' => PromoType::DISCOUNT,
            'discount_percentage' => 10,
            'is_active' => true,
        ]);

        $service = app(OrderFulfillmentService::class);

        $order = $service->createFromLeadWithCustomization($lead, [
            'quantity' => 2,
            'promo_ids' => [$promo->id],
        ]);

        // Subtotal = 2 * 200 = 400, discount = 40, total = 360
        $this->assertEquals(40.00, (float) $order->discount_amount);
        $this->assertEquals(360.00, (float) $order->total_amount);
    }

    public function test_customized_order_goes_to_qa_pending(): void
    {
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->create(['customer_id' => $customer->id]);

        $service = app(OrderFulfillmentService::class);

        $order = $service->createFromLeadWithCustomization($lead, [
            'quantity' => 1,
            'promo_ids' => [],
        ]);

        $this->assertEquals(OrderStatus::QA_PENDING, $order->status);
        $this->assertEquals(SalesStatus::QA_PENDING, $lead->fresh()->sales_status);
    }

    public function test_order_without_customization_data_still_works(): void
    {
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Test Customer',
            'phone' => '09123456789',
        ]);

        $service = app(OrderFulfillmentService::class);

        $order = $service->createFromLeadWithCustomization($lead, []);

        $this->assertEquals(1, $order->quantity);
        $this->assertEquals('Test Customer', $order->receiver_name);
        $this->assertEquals('09123456789', $order->receiver_phone);
    }
}
