<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use App\Domain\Shop\Models\AddressMapping;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\CustomerIdentity;
use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Models\ShopPageProductMapping;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Domain\Shop\Services\MetaConversationIngestor;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaConversationIngestorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_facebook_name_phone_and_address_into_auto_created_shop_order(): void
    {
        config(['services.meta.graph_version' => 'v21.0']);

        Http::fake([
            'https://graph.facebook.com/v21.0/customer-psid-1*' => Http::response([
                'id' => 'customer-psid-1',
                'name' => 'Maria Santos',
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'profile_pic' => 'https://example.test/maria.jpg',
            ]),
        ]);

        $page = FacebookPage::query()->create([
            'page_id' => 'page-123',
            'page_name' => 'AIO PH',
            'page_access_token' => 'page-token',
            'connected_status' => 'connected',
            'webhook_status' => 'subscribed',
        ]);

        AddressMapping::query()->create([
            'country' => 'PH',
            'region' => 'Region IV-A',
            'province' => 'Laguna',
            'city_municipality' => 'Calamba City',
            'barangay' => null,
            'postal_code' => '4027',
        ]);

        $event = FacebookWebhookEvent::query()->create([
            'facebook_page_id' => $page->id,
            'event_id' => 'mid-test-name-sync',
            'object' => 'page',
            'event_type' => 'message',
            'sender_psid' => 'customer-psid-1',
            'recipient_id' => $page->page_id,
            'payload' => [
                'sender' => ['id' => 'customer-psid-1'],
                'recipient' => ['id' => $page->page_id],
                'timestamp' => now()->getTimestampMs(),
                'message' => [
                    'mid' => 'mid-test-name-sync',
                    'text' => '09991234567 address: Blk 5 Lot 2 Laguna, Calamba City near gas station',
                ],
            ],
            'signature_valid' => true,
        ]);

        app(MetaConversationIngestor::class)->process($event);

        $order = Order::query()->firstOrFail();
        $customer = Customer::query()->firstOrFail();
        $identity = CustomerIdentity::query()->firstOrFail();
        $conversation = Conversation::query()->firstOrFail();

        $this->assertSame('Maria Santos', $order->receiver_name);
        $this->assertSame('09991234567', $order->receiver_phone);
        $this->assertSame('Blk 5 Lot 2 Laguna, Calamba City near gas station', $order->receiver_address);
        $this->assertSame('Calamba City', $order->city);
        $this->assertSame('Laguna', $order->state);

        $this->assertSame('Maria Santos', $customer->name);
        $this->assertSame('Maria Santos', $customer->facebook_name);
        $this->assertSame('09991234567', $customer->normalized_phone);
        $this->assertSame('Blk 5 Lot 2 Laguna, Calamba City near gas station', $customer->canonical_address);

        $this->assertSame('Maria Santos', $identity->display_name);
        $this->assertSame('https://example.test/maria.jpg', $identity->profile_pic_url);
        $this->assertSame($customer->id, $identity->customer_id);
        $this->assertSame($customer->id, $conversation->customer_id);
    }

    public function test_it_creates_line_items_from_messenger_product_text_when_catalog_matches(): void
    {
        config(['services.meta.graph_version' => 'v21.0']);

        Http::fake([
            'https://graph.facebook.com/v21.0/customer-psid-2*' => Http::response([
                'id' => 'customer-psid-2',
                'name' => 'Juan Dela Cruz',
            ]),
        ]);

        $page = FacebookPage::query()->create([
            'page_id' => 'page-456',
            'page_name' => 'AIO PH',
            'page_access_token' => 'page-token',
            'connected_status' => 'connected',
            'webhook_status' => 'subscribed',
        ]);

        $product = Product::query()->create([
            'sku' => 'AVOCAFE',
            'name' => 'AVOCAFE 1 SET',
            'brand' => 'AIO',
            'category' => 'Coffee',
            'selling_price' => 199,
            'cost_price' => 90,
            'weight_grams' => 100,
            'is_active' => true,
            'requires_qa' => false,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'AVOCAFE-B1T2',
            'variant_name' => 'B1T2',
            'selling_price' => 199,
            'cost_price' => 90,
            'weight_grams' => 100,
            'is_active' => true,
        ]);

        AddressMapping::query()->create([
            'country' => 'PH',
            'region' => 'Region IV-A',
            'province' => 'Laguna',
            'city_municipality' => 'Calamba City',
            'barangay' => null,
            'postal_code' => '4027',
        ]);

        $event = FacebookWebhookEvent::query()->create([
            'facebook_page_id' => $page->id,
            'event_id' => 'mid-test-product-sync',
            'object' => 'page',
            'event_type' => 'message',
            'sender_psid' => 'customer-psid-2',
            'recipient_id' => $page->page_id,
            'payload' => [
                'sender' => ['id' => 'customer-psid-2'],
                'recipient' => ['id' => $page->page_id],
                'timestamp' => now()->getTimestampMs(),
                'message' => [
                    'mid' => 'mid-test-product-sync',
                    'text' => 'Order AVOCAFE-B1T2 x2 09991234568 Blk 7 Lot 3 Calamba City Laguna',
                ],
            ],
            'signature_valid' => true,
        ]);

        app(MetaConversationIngestor::class)->process($event);

        $order = Order::query()->firstOrFail();
        $item = ShopOrderItem::query()->firstOrFail();

        $this->assertSame($order->id, $item->order_id);
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame($variant->id, $item->variant_id);
        $this->assertSame('AVOCAFE-B1T2', $item->sku);
        $this->assertSame('AVOCAFE 1 SET - B1T2', $item->product_name);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('199.00', (string) $item->unit_price);
        $this->assertSame('398.00', (string) $item->line_total);
        $this->assertSame(2, $order->quantity);
        $this->assertSame('398.00', (string) $order->total_amount);
        $this->assertSame('398.00', (string) $order->cod_amount);
    }

    public function test_it_infers_line_item_from_facebook_page_catalog_mapping_when_message_has_no_product_text(): void
    {
        config(['services.meta.graph_version' => 'v21.0']);

        Http::fake([
            'https://graph.facebook.com/v21.0/customer-psid-3*' => Http::response([
                'id' => 'customer-psid-3',
                'name' => 'Ana Reyes',
            ]),
        ]);

        $page = FacebookPage::query()->create([
            'page_id' => 'page-789',
            'page_name' => 'Akarui Aura',
            'page_access_token' => 'page-token',
            'connected_status' => 'connected',
            'webhook_status' => 'subscribed',
        ]);

        $product = Product::query()->create([
            'sku' => 'AKARUICOFFEE-1-SET-B1T2',
            'name' => 'AKARUICOFFEE 1 SET B1T2',
            'brand' => 'AKARUI HADA',
            'category' => 'Shop Catalog',
            'selling_price' => 199,
            'cost_price' => 90,
            'weight_grams' => 100,
            'is_active' => true,
            'requires_qa' => false,
        ]);

        ShopPageProductMapping::query()->create([
            'page_name' => 'Akarui Aura',
            'normalized_page_name' => ShopPageProductMapping::normalizePageName('Akarui Aura'),
            'brand_name' => 'AKARUI HADA',
            'remarks' => 'AKARUICOFFEE 1 SET B1T2',
            'product_id' => $product->id,
            'is_active' => true,
            'metadata' => ['source' => 'test'],
        ]);

        $event = FacebookWebhookEvent::query()->create([
            'facebook_page_id' => $page->id,
            'event_id' => 'mid-test-page-product-sync',
            'object' => 'page',
            'event_type' => 'message',
            'sender_psid' => 'customer-psid-3',
            'recipient_id' => $page->page_id,
            'payload' => [
                'sender' => ['id' => 'customer-psid-3'],
                'recipient' => ['id' => $page->page_id],
                'timestamp' => now()->getTimestampMs(),
                'message' => [
                    'mid' => 'mid-test-page-product-sync',
                    'text' => '09991234569 Blk 10 Lot 8 Calamba City Laguna',
                ],
            ],
            'signature_valid' => true,
        ]);

        app(MetaConversationIngestor::class)->process($event);

        $order = Order::query()->firstOrFail();
        $item = ShopOrderItem::query()->firstOrFail();

        $this->assertSame($product->id, $item->product_id);
        $this->assertNull($item->variant_id);
        $this->assertSame('AKARUICOFFEE-1-SET-B1T2', $item->sku);
        $this->assertSame('AKARUICOFFEE 1 SET B1T2', $item->product_name);
        $this->assertSame(1, $item->quantity);
        $this->assertSame('199.00', (string) $order->cod_amount);
    }
}
