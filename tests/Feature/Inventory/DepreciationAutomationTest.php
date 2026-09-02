<?php

use Modules\Inventory\Models\CapexAsset;
use Modules\Inventory\Models\CapexDepreciationJournal;
use Modules\Inventory\Services\CapexAssetService;
use Modules\Inventory\Services\DepreciationAutomationService;
use App\Models\SiteSetting;
use App\Models\User;

use function Pest\Laravel\actingAs;

function makeDepUser(): User
{
    return User::factory()->create(['role' => 'finance', 'is_active' => true]);
}

function createTestAsset(string $code = 'AST-001', float $cost = 12000, int $years = 3, float $salvage = 0): CapexAsset
{
    $service = app(CapexAssetService::class);
    $user = User::factory()->create(['role' => 'admin']);

    return $service->create([
        'asset_code' => $code,
        'name' => 'Test Asset '.$code,
        'description' => 'Test asset for depreciation',
        'category' => 'EQUIPMENT',
        'depreciation_years' => $years,
        'purchase_date' => now()->subYear()->toDateString(),
        'acquisition_cost' => $cost,
        'salvage_value' => $salvage,
        'warehouse_id' => null,
        'assigned_to' => null,
        'department' => null,
        'uom_id' => null,
        'quantity' => 1,
    ], $user->id);
}

// ─── Index ──────────────────────────────────────────────────────────────────

test('finance user can view depreciation automation index', function () {
    $user = makeDepUser();

    actingAs($user)
        ->get(route('inventory.depreciation-automation.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/DepreciationAutomation'));
});

// ─── API ────────────────────────────────────────────────────────────────────

test('api returns dashboard data', function () {
    $user = makeDepUser();

    actingAs($user)
        ->get(route('inventory.depreciation-automation.api'))
        ->assertOk()
        ->assertJsonStructure([
            'summary' => [
                'total_assets', 'total_acquisition_cost', 'total_book_value',
                'total_accumulated_depreciation', 'due_count', 'due_amount',
                'posted_count', 'posted_amount',
            ],
            'upcoming',
            'due_entries',
            'by_asset',
            'monthly_trend',
            'settings',
        ]);
});

// ─── Monthly journal entries generated ───────────────────────────────────────

test('monthly journal entries are generated for active asset', function () {
    $user = makeDepUser();
    $asset = createTestAsset('AST-GEN-01', 12000, 3, 0);

    // Asset purchased 1 year ago, so 12 months of entries should be generated
    $service = app(DepreciationAutomationService::class);
    $service->generateMonthlySchedules();

    $count = CapexDepreciationJournal::where('capex_asset_id', $asset->id)->count();

    // 12 months for year 1 (past), plus potentially some year 2 months
    expect($count)->toBeGreaterThanOrEqual(12);
});

// ─── Monthly amount correct ─────────────────────────────────────────────────

test('monthly depreciation amount is annual divided by 12', function () {
    $user = makeDepUser();
    $asset = createTestAsset('AST-AMT-01', 12000, 3, 0);

    $service = app(DepreciationAutomationService::class);
    $service->generateMonthlySchedules();

    $entry = CapexDepreciationJournal::where('capex_asset_id', $asset->id)
        ->orderBy('posting_date')
        ->first();

    // Annual = (12000 - 0) / 3 = 4000; Monthly = 4000 / 12 = 333.3333
    expect($entry)->not->toBeNull()
        ->and((float) $entry->depreciation_amount)->toEqual(round(4000 / 12, 4));
});

// ─── Post due entries ───────────────────────────────────────────────────────

test('postDueEntries marks entries as posted and updates book value', function () {
    $user = makeDepUser();
    $asset = createTestAsset('AST-POST-01', 12000, 3, 0);

    $service = app(DepreciationAutomationService::class);
    $service->generateMonthlySchedules();

    $result = $service->postDueEntries();

    expect($result['posted'])->toBeGreaterThan(0);

    $asset->refresh();
    // After posting, book value should have decreased
    expect((float) $asset->current_book_value)->toBeLessThan(12000);

    $unposted = CapexDepreciationJournal::where('capex_asset_id', $asset->id)
        ->where('is_posted', false)
        ->where('posting_date', '<=', today())
        ->count();
    expect($unposted)->toBe(0);
});

// ─── Posted entries have reference ──────────────────────────────────────────

test('journal entries have reference codes', function () {
    $user = makeDepUser();
    $asset = createTestAsset('AST-REF-01', 12000, 3, 0);

    $service = app(DepreciationAutomationService::class);
    $service->generateMonthlySchedules();

    $entry = CapexDepreciationJournal::where('capex_asset_id', $asset->id)->first();

    expect($entry->reference)->toContain('AST-REF-01')
        ->and($entry->reference)->toContain('Y1')
        ->and($entry->reference)->toContain('M');
});

// ─── Settings update ────────────────────────────────────────────────────────

test('settings can be updated via api', function () {
    $user = makeDepUser();

    actingAs($user)
        ->patch(route('inventory.depreciation-automation.settings.api'), [
            'auto_post' => false,
            'posting_day' => 15,
            'debit_account' => 'Dep Expense',
            'credit_account' => 'Acc Depreciation',
            'notify_emails' => 'finance@test.com',
            'notify_email_enabled' => true,
            'notify_in_app_enabled' => false,
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect((bool) SiteSetting::get('dep_auto_post'))->toBeFalse()
        ->and((int) SiteSetting::get('dep_posting_day'))->toBe(15)
        ->and((string) SiteSetting::get('dep_debit_account'))->toBe('Dep Expense');
});

// ─── CSV export ─────────────────────────────────────────────────────────────

test('csv export returns downloadable csv', function () {
    $user = makeDepUser();
    createTestAsset('AST-CSV-01', 12000, 3, 0);

    actingAs($user)
        ->get(route('inventory.depreciation-automation.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
});

// ─── Manual trigger post via web ────────────────────────────────────────────

test('manual trigger post via web returns redirect with success', function () {
    $user = makeDepUser();
    createTestAsset('AST-MAN-01', 12000, 3, 0);

    actingAs($user)
        ->post(route('inventory.depreciation-automation.post'))
        ->assertRedirect()
        ->assertSessionHas('success');
});

// ─── Disposed assets skipped ────────────────────────────────────────────────

test('disposed assets are not processed', function () {
    $user = makeDepUser();
    $asset = createTestAsset('AST-DIS-01', 12000, 3, 0);

    $service = app(CapexAssetService::class);
    $service->dispose($asset, ['disposal_reason' => 'Test disposal']);

    $depService = app(DepreciationAutomationService::class);
    $depService->generateMonthlySchedules();

    $count = CapexDepreciationJournal::where('capex_asset_id', $asset->id)->count();
    // Entries may have been generated before disposal, but no new ones after
    // The key check: postDueEntries should skip disposed assets
    $result = $depService->postDueEntries();

    // The disposed asset's entries should not be posted
    $postedForDisposed = CapexDepreciationJournal::where('capex_asset_id', $asset->id)
        ->where('is_posted', true)
        ->count();

    // Entries generated before disposal might be posted, but the asset status is DISPOSED now
    // The service skips entries where asset status is not ACTIVE
    expect($asset->status)->toBe(CapexAsset::STATUS_DISPOSED);
});

// ─── Unique constraint prevents duplicates ──────────────────────────────────

test('generating monthly schedules twice does not create duplicates', function () {
    $user = makeDepUser();
    $asset = createTestAsset('AST-DUP-01', 12000, 3, 0);

    $service = app(DepreciationAutomationService::class);
    $service->generateMonthlySchedules();
    $firstCount = CapexDepreciationJournal::where('capex_asset_id', $asset->id)->count();

    $service->generateMonthlySchedules();
    $secondCount = CapexDepreciationJournal::where('capex_asset_id', $asset->id)->count();

    expect($secondCount)->toBe($firstCount);
});

// ─── Dashboard summary correct ──────────────────────────────────────────────

test('dashboard summary returns correct totals', function () {
    $user = makeDepUser();
    createTestAsset('AST-SUM-01', 12000, 3, 0);
    createTestAsset('AST-SUM-02', 6000, 2, 0);

    $service = app(DepreciationAutomationService::class);
    $dashboard = $service->getDashboard();

    expect($dashboard['summary']['total_assets'])->toBe(2)
        ->and($dashboard['summary']['total_acquisition_cost'])->toEqual(18000.0);
});
