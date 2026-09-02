<?php

use Modules\Inventory\Models\Supply;
use Modules\Inventory\Services\BarcodeLabelService;
use Modules\Products\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;

use function Pest\Laravel\actingAs;

function makeBarcodeUser(): User
{
    return User::factory()->create(['role' => 'warehouse', 'is_active' => true]);
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('warehouse user can view barcode labels index', function () {
    $user = makeBarcodeUser();

    actingAs($user)
        ->get(route('inventory.barcode-labels.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/BarcodeLabels'));
});

// ─── API ────────────────────────────────────────────────────────────────────

test('api returns dashboard and items', function () {
    $user = makeBarcodeUser();

    actingAs($user)
        ->get(route('inventory.barcode-labels.api'))
        ->assertOk()
        ->assertJsonStructure([
            'dashboard' => [
                'summary', 'settings', 'formats', 'label_sizes',
            ],
            'items',
        ]);
});

// ─── Items endpoint ─────────────────────────────────────────────────────────

test('items endpoint returns products', function () {
    $user = makeBarcodeUser();
    Product::factory()->create(['is_active' => true, 'name' => 'Test Product ABC']);

    actingAs($user)
        ->get(route('inventory.barcode-labels.items', ['search' => 'ABC']))
        ->assertOk()
        ->assertJsonStructure(['items']);
});

// ─── Items filter by type ───────────────────────────────────────────────────

test('items endpoint filters by item type', function () {
    $user = makeBarcodeUser();
    Product::factory()->create(['is_active' => true, 'name' => 'Product XYZ']);
    Supply::factory()->create(['is_active' => true, 'name' => 'Supply XYZ']);

    $response = actingAs($user)
        ->get(route('inventory.barcode-labels.items', ['item_type' => 'product', 'search' => 'XYZ']))
        ->assertOk();

    $items = collect($response->json('items'));
    expect($items->every(fn ($i) => $i['type'] === 'product'))->toBeTrue();
});

// ─── Items without barcode ──────────────────────────────────────────────────

test('items endpoint filters without barcode', function () {
    $user = makeBarcodeUser();
    Product::factory()->create(['is_active' => true, 'barcode' => 'HAS001', 'name' => 'With Barcode']);
    Product::factory()->create(['is_active' => true, 'barcode' => null, 'name' => 'Without Barcode']);

    $response = actingAs($user)
        ->get(route('inventory.barcode-labels.items', ['without_barcode' => '1']))
        ->assertOk();

    $items = collect($response->json('items'));
    expect($items->contains(fn ($i) => $i['barcode'] === null || $i['barcode'] === ''))->toBeTrue();
});

// ─── Generate labels ────────────────────────────────────────────────────────

test('generate labels returns label data', function () {
    $user = makeBarcodeUser();
    $product = Product::factory()->create(['is_active' => true, 'barcode' => 'TEST001']);

    actingAs($user)
        ->post(route('inventory.barcode-labels.generate'), [
            'items' => [
                [
                    'type' => 'product',
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'barcode' => $product->barcode,
                    'price' => (float) $product->selling_price,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonStructure(['labels', 'count', 'label_size', 'format']);
});

// ─── Generate labels with copies ────────────────────────────────────────────

test('generate labels respects copies setting', function () {
    SiteSetting::set('barcode_copies', 3);

    $user = makeBarcodeUser();
    $product = Product::factory()->create(['is_active' => true, 'barcode' => 'TEST002']);

    $response = actingAs($user)
        ->post(route('inventory.barcode-labels.generate'), [
            'items' => [
                [
                    'type' => 'product',
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'barcode' => $product->barcode,
                    'price' => (float) $product->selling_price,
                ],
            ],
        ])
        ->assertOk();

    expect($response->json('count'))->toBe(3);
});

// ─── Auto-generate barcodes ─────────────────────────────────────────────────

test('auto-generate creates barcodes for products without one', function () {
    $user = makeBarcodeUser();
    Product::factory()->create(['is_active' => true, 'barcode' => null]);
    Product::factory()->create(['is_active' => true, 'barcode' => null]);

    $response = actingAs($user)
        ->post(route('inventory.barcode-labels.auto-generate.api'))
        ->assertOk();

    expect($response->json('generated'))->toBe(2);
});

// ─── Auto-generate skips products with barcode ──────────────────────────────

test('auto-generate skips products with existing barcode', function () {
    $user = makeBarcodeUser();
    Product::factory()->create(['is_active' => true, 'barcode' => 'EXISTING001']);
    Product::factory()->create(['is_active' => true, 'barcode' => null]);

    $response = actingAs($user)
        ->post(route('inventory.barcode-labels.auto-generate.api'))
        ->assertOk();

    expect($response->json('generated'))->toBe(1);
});

// ─── Assign barcode ─────────────────────────────────────────────────────────

test('assign barcode to product via api', function () {
    $user = makeBarcodeUser();
    $product = Product::factory()->create(['is_active' => true, 'barcode' => null]);

    actingAs($user)
        ->post(route('inventory.barcode-labels.assign.api'), [
            'product_id' => $product->id,
            'barcode' => 'ASSIGNED001',
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($product->fresh()->barcode)->toBe('ASSIGNED001');
});

// ─── Settings update ────────────────────────────────────────────────────────

test('settings can be updated via api', function () {
    $user = makeBarcodeUser();

    actingAs($user)
        ->patch(route('inventory.barcode-labels.settings.api'), [
            'format' => 'EAN13',
            'label_size' => 'small',
            'include_name' => true,
            'include_sku' => false,
            'include_price' => true,
            'include_barcode_text' => false,
            'copies' => 2,
            'auto_generate' => true,
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect((string) SiteSetting::get('barcode_format'))->toBe('EAN13')
        ->and((string) SiteSetting::get('barcode_label_size'))->toBe('small')
        ->and((int) SiteSetting::get('barcode_copies'))->toBe(2);
});

// ─── Barcode format CODE128 ─────────────────────────────────────────────────

test('generateBarcode produces CODE128 format by default', function () {
    $service = app(BarcodeLabelService::class);
    SiteSetting::set('barcode_format', 'CODE128');

    $barcode = $service->generateBarcode();

    expect($barcode)->toStartWith('WH')
        ->and(strlen($barcode))->toBe(12);
});

// ─── Barcode format EAN13 ───────────────────────────────────────────────────

test('generateBarcode produces valid EAN13 format', function () {
    $service = app(BarcodeLabelService::class);
    SiteSetting::set('barcode_format', 'EAN13');

    $barcode = $service->generateBarcode();

    expect(strlen($barcode))->toBe(13)
        ->and(ctype_digit($barcode))->toBeTrue();
});

// ─── Barcode format QR ──────────────────────────────────────────────────────

test('generateBarcode produces QR format', function () {
    $service = app(BarcodeLabelService::class);
    SiteSetting::set('barcode_format', 'QR');

    $barcode = $service->generateBarcode();

    expect($barcode)->toStartWith('QR-');
});

// ─── Dashboard summary ──────────────────────────────────────────────────────

test('dashboard summary returns correct counts', function () {
    Product::factory()->create(['is_active' => true, 'barcode' => 'DASH001']);
    Product::factory()->create(['is_active' => true, 'barcode' => null]);
    Supply::factory()->create(['is_active' => true]);

    $service = app(BarcodeLabelService::class);
    $dashboard = $service->getDashboard();

    expect($dashboard['summary']['total_products'])->toBeGreaterThanOrEqual(2)
        ->and($dashboard['summary']['products_without_barcode'])->toBeGreaterThanOrEqual(1);
});

// ─── Manual auto-generate via web ───────────────────────────────────────────

test('auto-generate via web returns redirect with success', function () {
    $user = makeBarcodeUser();
    Product::factory()->create(['is_active' => true, 'barcode' => null]);

    actingAs($user)
        ->post(route('inventory.barcode-labels.auto-generate'))
        ->assertRedirect()
        ->assertSessionHas('success');
});
