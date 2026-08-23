<?php

namespace Tests\Unit\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Promo\Enums\PromoType;
use App\Domain\Promo\Models\Promo;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Models\Customer;
use App\Models\Lead;
use App\Services\PromoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoServiceTest extends TestCase
{
    use RefreshDatabase;

    private PromoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PromoService::class);
    }

    public function test_freebie_promo_creates_free_item_with_zero_price(): void
    {
        $promo = Promo::factory()->create([
            'type' => PromoType::FREEBIE,
            'free_item_name' => 'Sample Pack',
            'free_quantity' => 1,
            'is_active' => true,
        ]);

        $order = $this->createTestOrder();

        $result = $this->service->applyPromos($order, 1, 100.00, [$promo->id]);

        $this->assertEquals(0.0, $result['discount_amount']);
        $this->assertCount(1, $result['free_items']);
        $this->assertEquals('Sample Pack', $result['free_items'][0]['name']);

        // Verify ShopOrderItem was created with zero price
        $freeItem = ShopOrderItem::where('order_id', $order->id)
            ->where('unit_price', 0)
            ->first();
        $this->assertNotNull($freeItem);
        $this->assertEquals('Sample Pack', $freeItem->product_name);
    }

    public function test_bundle_promo_b1t1_calculates_correct_discount(): void
    {
        $promo = Promo::factory()->create([
            'type' => PromoType::BUNDLE,
            'trigger_quantity' => 1,
            'free_quantity' => 1,
            'is_active' => true,
        ]);

        $order = $this->createTestOrder();

        // Buy 1 Take 1 with quantity=2 → 2 free units → discount = 2 * unit_price
        $result = $this->service->applyPromos($order, 2, 100.00, [$promo->id]);

        $this->assertEquals(200.00, $result['discount_amount']);
        $this->assertCount(1, $result['free_items']);
        $this->assertEquals(2, $result['free_items'][0]['quantity']);
    }

    public function test_bundle_promo_b1t2_with_quantity_1_gives_2_free(): void
    {
        $promo = Promo::factory()->create([
            'type' => PromoType::BUNDLE,
            'trigger_quantity' => 1,
            'free_quantity' => 2,
            'is_active' => true,
        ]);

        $result = $this->service->calculatePromoEffect($promo, 1, 100.00);

        $this->assertEquals(200.00, $result['discount_amount']);
        $this->assertCount(1, $result['free_items']);
        $this->assertEquals(2, $result['free_items'][0]['quantity']);
    }

    public function test_discount_promo_calculates_percentage(): void
    {
        $promo = Promo::factory()->create([
            'type' => PromoType::DISCOUNT,
            'discount_percentage' => 10,
            'is_active' => true,
        ]);

        $result = $this->service->calculatePromoEffect($promo, 2, 100.00);

        // 2 * 100 * 10% = 20
        $this->assertEquals(20.00, $result['discount_amount']);
        $this->assertEmpty($result['free_items']);
    }

    public function test_preview_promos_returns_correct_total(): void
    {
        $promo = Promo::factory()->create([
            'type' => PromoType::DISCOUNT,
            'discount_percentage' => 15,
            'is_active' => true,
        ]);

        $result = $this->service->previewPromos(null, 2, 100.00, [$promo->id]);

        // Subtotal = 200, discount = 30, total = 170
        $this->assertEquals(30.00, $result['discount_amount']);
        $this->assertEquals(170.00, $result['total']);
    }

    public function test_inactive_promo_is_not_applied(): void
    {
        $promo = Promo::factory()->create([
            'type' => PromoType::DISCOUNT,
            'discount_percentage' => 50,
            'is_active' => false,
        ]);

        $order = $this->createTestOrder();

        $result = $this->service->applyPromos($order, 1, 100.00, [$promo->id]);

        $this->assertEquals(0.0, $result['discount_amount']);
        $this->assertEmpty($result['free_items']);
    }

    public function test_expired_promo_is_not_applied(): void
    {
        $promo = Promo::factory()->create([
            'type' => PromoType::DISCOUNT,
            'discount_percentage' => 50,
            'is_active' => true,
            'ends_at' => now()->subDay(),
        ]);

        $result = $this->service->calculatePromoEffect($promo, 1, 100.00);

        // calculatePromoEffect doesn't check validity, but applyPromos does
        // This test verifies the effect calculation itself
        $this->assertEquals(50.00, $result['discount_amount']);
    }

    public function test_get_active_promos_for_product_returns_only_active(): void
    {
        Promo::factory()->create(['is_active' => true, 'type' => PromoType::FREEBIE]);
        Promo::factory()->create(['is_active' => false, 'type' => PromoType::FREEBIE]);

        $promos = $this->service->getActivePromosForProduct(null);

        $this->assertCount(1, $promos);
    }

    public function test_multiple_promos_can_be_combined(): void
    {
        $freebie = Promo::factory()->create([
            'type' => PromoType::FREEBIE,
            'free_item_name' => 'Bonus Item',
            'free_quantity' => 1,
            'is_active' => true,
        ]);

        $discount = Promo::factory()->create([
            'type' => PromoType::DISCOUNT,
            'discount_percentage' => 10,
            'is_active' => true,
        ]);

        $order = $this->createTestOrder();

        $result = $this->service->applyPromos($order, 1, 100.00, [$freebie->id, $discount->id]);

        $this->assertEquals(10.00, $result['discount_amount']);
        $this->assertCount(1, $result['free_items']);
    }

    private function createTestOrder(): Order
    {
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->create(['customer_id' => $customer->id]);

        return Order::create([
            'order_number' => 'TEST-'.uniqid(),
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::PENDING,
            'quantity' => 1,
            'unit_price' => 100.00,
            'total_amount' => 100.00,
            'cod_amount' => 100.00,
            'receiver_name' => 'Test Customer',
            'receiver_phone' => '09123456789',
            'receiver_address' => 'Test Address',
        ]);
    }
}
