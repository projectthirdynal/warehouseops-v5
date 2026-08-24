<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\TelesalesBrandConfig;
use App\Domain\Shop\Models\AddressMapping;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Models\Waybill;
use App\Services\LeadEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LeadEligibilityService::class);

        // Set default max age
        SystemSetting::set('telesales_max_waybill_age_days', 60, 'telesales', 'int');
    }

    // ─── Base Eligibility ───────────────────────────────────────────────

    public function test_available_lead_with_recent_delivered_waybill_is_eligible(): void
    {
        $customer = Customer::factory()->create([
            'is_blacklisted' => false,
            'do_not_call' => false,
        ]);

        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(10),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'is_exhausted' => false,
            'phone' => '09171234567',
        ]);

        $count = $this->service->countEligible();

        $this->assertEquals(1, $count);
    }

    public function test_assigned_lead_is_not_eligible(): void
    {
        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::ASSIGNED,
            'source_waybill_id' => $waybill->id,
            'phone' => '09171234567',
        ]);

        $this->assertEquals(0, $this->service->countEligible());
    }

    public function test_exhausted_lead_is_not_eligible(): void
    {
        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'is_exhausted' => true,
            'phone' => '09171234567',
        ]);

        $this->assertEquals(0, $this->service->countEligible());
    }

    public function test_lead_without_source_waybill_is_not_eligible(): void
    {
        $customer = Customer::factory()->create();

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => null,
            'phone' => '09171234567',
        ]);

        $this->assertEquals(0, $this->service->countEligible());
    }

    public function test_lead_with_old_waybill_is_not_eligible(): void
    {
        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(90), // older than 60-day max
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'phone' => '09171234567',
        ]);

        $this->assertEquals(0, $this->service->countEligible());
    }

    public function test_lead_with_non_delivered_waybill_is_not_eligible(): void
    {
        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'PENDING',
            'delivered_at' => null,
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'phone' => '09171234567',
        ]);

        $this->assertEquals(0, $this->service->countEligible());
    }

    // ─── Customer Exclusions ────────────────────────────────────────────

    public function test_blacklisted_customer_lead_is_not_eligible(): void
    {
        $customer = Customer::factory()->create(['is_blacklisted' => true]);
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'phone' => '09171234567',
        ]);

        $this->assertEquals(0, $this->service->countEligible());
    }

    public function test_do_not_call_customer_lead_is_not_eligible(): void
    {
        $customer = Customer::factory()->create(['do_not_call' => true]);
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'phone' => '09171234567',
        ]);

        $this->assertEquals(0, $this->service->countEligible());
    }

    // ─── Phone Validation ───────────────────────────────────────────────

    public function test_lead_with_empty_phone_is_not_eligible(): void
    {
        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'phone' => '',
        ]);

        $this->assertEquals(0, $this->service->countEligible());
    }

    public function test_lead_with_null_phone_is_not_eligible(): void
    {
        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        // The leads table has a NOT NULL constraint on phone, so we can only
        // test with an empty string (which the service should also reject).
        // A DB-level NULL would be rejected by the schema itself.
        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'phone' => '',
        ]);

        $this->assertEquals(0, $this->service->countEligible());
    }

    // ─── Brand Filtering ────────────────────────────────────────────────

    public function test_brand_filter_matches_product_name(): void
    {
        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        TelesalesBrandConfig::create([
            'brand_name' => 'Black Garlic',
            'display_name' => 'Black Garlic',
            'is_active' => true,
            'match_patterns' => ['Black Garlic', 'Black Garlic Coffee'],
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'product_name' => 'Black Garlic Coffee 30 capsules',
            'phone' => '09171234567',
        ]);

        Lead::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => Waybill::factory()->create(['status' => 'DELIVERED', 'delivered_at' => now()->subDays(5)])->id,
            'product_name' => 'Barley Grass Powder',
            'phone' => '09187654321',
        ]);

        $count = $this->service->countEligible(['brand' => 'Black Garlic']);

        $this->assertEquals(1, $count);
    }

    public function test_brand_filter_with_no_config_falls_back_to_name_match(): void
    {
        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'product_name' => 'EyeCare Drops',
            'phone' => '09171234567',
        ]);

        // No TelesalesBrandConfig for 'EyeCare' — should fall back to ILIKE
        $count = $this->service->countEligible(['brand' => 'EyeCare']);

        $this->assertEquals(1, $count);
    }

    // ─── Region Filtering ───────────────────────────────────────────────

    public function test_region_filter_via_address_mapping(): void
    {
        $mapping = AddressMapping::create([
            'country' => 'PH',
            'region' => 'Region III',
            'province' => 'Bulacan',
            'city_municipality' => 'Malolos',
            'barangay' => null,
            'business_region' => 'North Luzon',
        ]);

        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'address_mapping_id' => $mapping->id,
            'phone' => '09171234567',
        ]);

        $count = $this->service->countEligible(['business_region' => 'North Luzon']);
        $this->assertEquals(1, $count);

        $count = $this->service->countEligible(['business_region' => 'NCR']);
        $this->assertEquals(0, $count);
    }

    // ─── Age Range Filtering ────────────────────────────────────────────

    public function test_age_range_filter_0_7_days(): void
    {
        $customer = Customer::factory()->create();

        $recentWaybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(3),
        ]);

        $olderWaybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(20),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $recentWaybill->id,
            'phone' => '09171234567',
        ]);

        Lead::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $olderWaybill->id,
            'phone' => '09187654321',
        ]);

        $count = $this->service->countEligible(['age_from' => 0, 'age_to' => 7]);
        $this->assertEquals(1, $count);
    }

    // ─── Source Filtering ───────────────────────────────────────────────

    public function test_source_filter(): void
    {
        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'source' => LeadSource::DELIVERED_WAYBILL,
            'phone' => '09171234567',
        ]);

        Lead::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => Waybill::factory()->create(['status' => 'DELIVERED', 'delivered_at' => now()->subDays(5)])->id,
            'source' => LeadSource::MANUAL,
            'phone' => '09187654321',
        ]);

        $count = $this->service->countEligible(['source' => 'DELIVERED_WAYBILL']);
        $this->assertEquals(1, $count);
    }

    // ─── Configurable Max Age ───────────────────────────────────────────

    public function test_configurable_max_age_respected(): void
    {
        SystemSetting::set('telesales_max_waybill_age_days', 30, 'telesales', 'int');

        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(45), // within 60-day default, outside 30-day config
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'phone' => '09171234567',
        ]);

        $this->assertEquals(0, $this->service->countEligible());
    }

    // ─── Inventory Breakdown ────────────────────────────────────────────

    public function test_inventory_breakdown_returns_rows(): void
    {
        $mapping = AddressMapping::create([
            'country' => 'PH',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city_municipality' => 'Quezon City',
            'business_region' => 'NCR',
        ]);

        TelesalesBrandConfig::create([
            'brand_name' => 'Black Garlic',
            'display_name' => 'Black Garlic',
            'is_active' => true,
            'match_patterns' => ['Black Garlic'],
        ]);

        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(3),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'address_mapping_id' => $mapping->id,
            'product_name' => 'Black Garlic Coffee',
            'phone' => '09171234567',
        ]);

        $breakdown = $this->service->getInventoryBreakdown();

        $this->assertNotEmpty($breakdown);

        $row = collect($breakdown)->firstWhere('region', 'NCR');
        $this->assertNotNull($row);
        $this->assertEquals('Black Garlic', $row['brand']);
        $this->assertGreaterThan(0, $row['age_0_7']);
        $this->assertGreaterThan(0, $row['total']);
    }

    // ─── Combined Filters ───────────────────────────────────────────────

    public function test_combined_brand_and_region_filter(): void
    {
        $mapping = AddressMapping::create([
            'country' => 'PH',
            'region' => 'Region IV-A',
            'province' => 'Cavite',
            'city_municipality' => 'Bacoor',
            'business_region' => 'South Luzon',
        ]);

        TelesalesBrandConfig::create([
            'brand_name' => 'Barley',
            'display_name' => 'Barley',
            'is_active' => true,
            'match_patterns' => ['Barley'],
        ]);

        $customer = Customer::factory()->create();
        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(15),
        ]);

        Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source_waybill_id' => $waybill->id,
            'address_mapping_id' => $mapping->id,
            'product_name' => 'Barley Grass Powder',
            'phone' => '09171234567',
        ]);

        $count = $this->service->countEligible([
            'brand' => 'Barley',
            'business_region' => 'South Luzon',
        ]);

        $this->assertEquals(1, $count);

        // Wrong region should return 0
        $count = $this->service->countEligible([
            'brand' => 'Barley',
            'business_region' => 'Mindanao',
        ]);

        $this->assertEquals(0, $count);
    }
}
