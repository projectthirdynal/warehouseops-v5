<?php

use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\Supply;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\AdjustmentBulkImportService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;

use function Pest\Laravel\actingAs;

function makeBulkImportUser(): User
{
    return User::factory()->create(['role' => 'warehouse', 'is_active' => true]);
}

function makeBulkImportWarehouse(): Warehouse
{
    return Warehouse::factory()->create(['is_active' => true, 'is_default' => true]);
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('warehouse user can view bulk import index', function () {
    $user = makeBulkImportUser();

    actingAs($user)
        ->get(route('inventory.adjustment-bulk-import.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/AdjustmentBulkImport'));
});

// ─── Template download ──────────────────────────────────────────────────────

test('template download returns csv', function () {
    $user = makeBulkImportUser();

    actingAs($user)
        ->get(route('inventory.adjustment-bulk-import.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
});

// ─── Preview valid CSV ──────────────────────────────────────────────────────

test('preview returns valid rows for correct csv', function () {
    $user = makeBulkImportUser();
    $warehouse = makeBulkImportWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'sku' => 'PROD-001']);

    $csv = "item_type,sku,warehouse_code,quantity_after,reason_code\nproduct,PROD-001,{$warehouse->code},150,CYCLE_COUNT\n";

    $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

    actingAs($user)
        ->post(route('inventory.adjustment-bulk-import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJsonStructure([
            'headers', 'valid_rows', 'error_rows', 'warnings', 'summary',
        ]);
});

// ─── Preview with missing headers ───────────────────────────────────────────

test('preview returns error for missing required headers', function () {
    $user = makeBulkImportUser();

    $csv = "item_type,sku,quantity_after\nproduct,PROD-001,100\n";

    $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

    actingAs($user)
        ->post(route('inventory.adjustment-bulk-import.preview'), ['file' => $file])
        ->assertStatus(422)
        ->assertJsonStructure(['errors']);
});

// ─── Preview with invalid item type ─────────────────────────────────────────

test('preview flags invalid item type', function () {
    $user = makeBulkImportUser();
    $warehouse = makeBulkImportWarehouse();

    $csv = "item_type,sku,warehouse_code,quantity_after,reason_code\nequipment,SKU-001,{$warehouse->code},100,CYCLE_COUNT\n";

    $file = UploadedFile::fake()->CreateWithContent('test.csv', $csv);

    actingAs($user)
        ->post(route('inventory.adjustment-bulk-import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('summary.error_count', 1)->etc());
});

// ─── Preview with invalid reason code ───────────────────────────────────────

test('preview flags invalid reason code', function () {
    $user = makeBulkImportUser();
    $warehouse = makeBulkImportWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'sku' => 'PROD-002']);

    $csv = "item_type,sku,warehouse_code,quantity_after,reason_code\nproduct,PROD-002,{$warehouse->code},100,INVALID_REASON\n";

    $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

    actingAs($user)
        ->post(route('inventory.adjustment-bulk-import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('summary.error_count', 1)->etc());
});

// ─── Preview with non-existent SKU ──────────────────────────────────────────

test('preview flags non-existent sku', function () {
    $user = makeBulkImportUser();
    $warehouse = makeBulkImportWarehouse();

    $csv = "item_type,sku,warehouse_code,quantity_after,reason_code\nproduct,NOT-EXIST,{$warehouse->code},100,CYCLE_COUNT\n";

    $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

    actingAs($user)
        ->post(route('inventory.adjustment-bulk-import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('summary.error_count', 1)->etc());
});

// ─── Preview with negative quantity ─────────────────────────────────────────

test('preview flags negative quantity', function () {
    $user = makeBulkImportUser();
    $warehouse = makeBulkImportWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'sku' => 'PROD-003']);

    $csv = "item_type,sku,warehouse_code,quantity_after,reason_code\nproduct,PROD-003,{$warehouse->code},-5,CYCLE_COUNT\n";

    $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

    actingAs($user)
        ->post(route('inventory.adjustment-bulk-import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('summary.error_count', 1)->etc());
});

// ─── Confirm import creates pending adjustments ─────────────────────────────

test('confirm creates pending stock adjustments', function () {
    $user = makeBulkImportUser();
    $warehouse = makeBulkImportWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'sku' => 'PROD-004']);
    $supply = Supply::factory()->create(['is_active' => true, 'sku' => 'SUP-004']);

    $rows = [
        [
            'item_type' => 'product',
            'sku' => 'PROD-004',
            'variant_sku' => null,
            'variant_id' => null,
            'warehouse_code' => $warehouse->code,
            'quantity_after' => 150,
            'reason_code' => 'CYCLE_COUNT',
            'reason_notes' => 'Annual count',
            'row_number' => 1,
        ],
        [
            'item_type' => 'supply',
            'sku' => 'SUP-004',
            'variant_sku' => null,
            'variant_id' => null,
            'warehouse_code' => $warehouse->code,
            'quantity_after' => 75,
            'reason_code' => 'DAMAGE',
            'reason_notes' => 'Water damage',
            'row_number' => 2,
        ],
    ];

    actingAs($user)
        ->post(route('inventory.adjustment-bulk-import.confirm'), ['rows' => $rows])
        ->assertOk()
        ->assertJson(['created' => 2, 'errors' => []]);

    expect(StockAdjustment::where('submitted_by', $user->id)->count())->toBe(2)
        ->and(StockAdjustment::where('status', 'PENDING')->count())->toBe(2);
});

// ─── Confirm with variant ───────────────────────────────────────────────────

test('confirm creates adjustment with variant', function () {
    $user = makeBulkImportUser();
    $warehouse = makeBulkImportWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'sku' => 'PROD-005']);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'is_active' => true,
        'sku' => 'VAR-005',
        'variant_name' => 'Test Variant',
        'selling_price' => 100,
        'cost_price' => 50,
    ]);

    $rows = [
        [
            'item_type' => 'product',
            'sku' => 'PROD-005',
            'variant_sku' => 'VAR-005',
            'variant_id' => $variant->id,
            'warehouse_code' => $warehouse->code,
            'quantity_after' => 50,
            'reason_code' => 'INITIAL_STOCK',
            'reason_notes' => null,
            'row_number' => 1,
        ],
    ];

    actingAs($user)
        ->post(route('inventory.adjustment-bulk-import.confirm'), ['rows' => $rows])
        ->assertOk()
        ->assertJson(['created' => 1]);

    $adj = StockAdjustment::where('product_id', $product->id)->first();
    expect($adj)->not->toBeNull()
        ->and($adj->variant_id)->toBe($variant->id)
        ->and($adj->status)->toBe('PENDING');
});

// ─── Service: parseCsv ──────────────────────────────────────────────────────

test('service parses csv correctly', function () {
    $service = app(AdjustmentBulkImportService::class);

    $csv = "item_type,sku,warehouse_code,quantity_after,reason_code,variant_sku,reason_notes\nproduct,SKU1,WH1,100,CYCLE_COUNT,,Notes here\n";

    $path = tempnam(sys_get_temp_dir(), 'csv_');
    file_put_contents($path, $csv);

    $result = $service->parseCsv($path);

    expect($result['errors'])->toBeEmpty()
        ->and($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['sku'])->toBe('SKU1')
        ->and($result['rows'][0]['reason_notes'])->toBe('Notes here');

    unlink($path);
});

// ─── Service: generateTemplate ──────────────────────────────────────────────

test('service generates template with correct headers', function () {
    $service = app(AdjustmentBulkImportService::class);

    $template = $service->generateTemplate();

    expect($template)->toContain('item_type')
        ->toContain('sku')
        ->toContain('warehouse_code')
        ->toContain('quantity_after')
        ->toContain('reason_code')
        ->toContain('variant_sku')
        ->toContain('reason_notes');
});

// ─── Service: import creates adjustments ────────────────────────────────────

test('service import creates stock adjustments', function () {
    $warehouse = makeBulkImportWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'sku' => 'PROD-SVC1']);
    $user = makeBulkImportUser();

    $service = app(AdjustmentBulkImportService::class);

    $rows = [
        [
            'item_type' => 'product',
            'sku' => 'PROD-SVC1',
            'variant_sku' => null,
            'variant_id' => null,
            'warehouse_code' => $warehouse->code,
            'quantity_after' => 200,
            'reason_code' => 'CYCLE_COUNT',
            'reason_notes' => 'Service test',
            'row_number' => 1,
        ],
    ];

    $result = $service->import($rows, $user->id);

    expect($result['created'])->toBe(1)
        ->and($result['errors'])->toBeEmpty();

    $adj = StockAdjustment::where('product_id', $product->id)->first();
    expect($adj)->not->toBeNull()
        ->and($adj->quantity_after)->toBe(200)
        ->and($adj->reason_code)->toBe('CYCLE_COUNT')
        ->and($adj->status)->toBe('PENDING');
});

// ─── Service: validateRows with empty rows ──────────────────────────────────

test('service validateRows handles empty input', function () {
    $service = app(AdjustmentBulkImportService::class);

    $result = $service->validateRows([]);

    expect($result['summary']['total_rows'])->toBe(0)
        ->and($result['valid_rows'])->toBeEmpty()
        ->and($result['error_rows'])->toBeEmpty();
});

// ─── Service: validateRows with supply item ─────────────────────────────────

test('service validateRows resolves supply item', function () {
    $warehouse = makeBulkImportWarehouse();
    $supply = Supply::factory()->create(['is_active' => true, 'sku' => 'SUP-VAL1']);

    $service = app(AdjustmentBulkImportService::class);

    $result = $service->validateRows([
        [
            'item_type' => 'supply',
            'sku' => 'SUP-VAL1',
            'warehouse_code' => $warehouse->code,
            'quantity_after' => '50',
            'reason_code' => 'DAMAGE',
            'variant_sku' => '',
            'reason_notes' => 'Test',
            '_row_number' => 1,
        ],
    ]);

    expect($result['summary']['valid_count'])->toBe(1)
        ->and($result['valid_rows'][0]['item_name'])->toBe($supply->name)
        ->and($result['valid_rows'][0]['variance'])->toBe(50);
});

// ─── Preview with mixed valid and invalid rows ──────────────────────────────

test('preview handles mixed valid and invalid rows', function () {
    $user = makeBulkImportUser();
    $warehouse = makeBulkImportWarehouse();
    $product = Product::factory()->create(['is_active' => true, 'sku' => 'PROD-MIX1']);

    $csv = "item_type,sku,warehouse_code,quantity_after,reason_code\nproduct,PROD-MIX1,{$warehouse->code},100,CYCLE_COUNT\nproduct,NOT-EXIST,{$warehouse->code},50,CYCLE_COUNT\nproduct,PROD-MIX1,{$warehouse->code},-10,CYCLE_COUNT\n";

    $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

    actingAs($user)
        ->post(route('inventory.adjustment-bulk-import.preview'), ['file' => $file])
        ->assertOk()
        ->assertJson(fn ($json) => $json
            ->where('summary.total_rows', 3)
            ->where('summary.valid_count', 1)
            ->where('summary.error_count', 2)
            ->etc()
        );
});
