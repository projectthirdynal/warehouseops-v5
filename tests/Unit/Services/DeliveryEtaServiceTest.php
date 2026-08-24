<?php

namespace Tests\Unit\Services;

use App\Domain\Courier\Models\ShippingDay;
use App\Domain\Courier\Services\DeliveryEtaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryEtaServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryEtaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DeliveryEtaService::class);
    }

    public function test_eta_uses_courier_table_for_exact_barangay_match(): void
    {
        ShippingDay::create([
            'province' => 'METRO-MANILA',
            'city' => 'QUEZON-CITY',
            'barangay' => 'BAHAY-TORO',
            'shipping_days' => 2,
        ]);

        $eta = $this->service->estimateEta('Metro Manila', 'Quezon City', 'Bahay Toro');

        $this->assertEquals(2, $eta['eta_days']);
        $this->assertEquals('courier_table', $eta['source']);
    }

    public function test_eta_falls_back_to_city_match_when_barangay_not_found(): void
    {
        ShippingDay::create([
            'province' => 'CEBU',
            'city' => 'CEBU-CITY',
            'barangay' => 'LAHUG',
            'shipping_days' => 4,
        ]);

        // Different barangay, same city — should fall back to city match
        $eta = $this->service->estimateEta('Cebu', 'Cebu City', 'Some Other Barangay');

        $this->assertEquals(4, $eta['eta_days']);
        $this->assertEquals('courier_table', $eta['source']);
    }

    public function test_eta_falls_back_to_province_match_when_city_not_found(): void
    {
        ShippingDay::create([
            'province' => 'ABRA',
            'city' => 'BANGUED',
            'barangay' => 'ZONE-1',
            'shipping_days' => 3,
        ]);

        // Different city, same province — should fall back to province match
        $eta = $this->service->estimateEta('Abra', 'Some Other City');

        $this->assertEquals(3, $eta['eta_days']);
        $this->assertEquals('courier_table', $eta['source']);
    }

    public function test_eta_falls_back_to_island_estimate_when_no_courier_data(): void
    {
        // No ShippingDay record exists for this province
        $eta = $this->service->estimateEta('Iloilo', null);

        $this->assertEquals('Visayas', $eta['island']);
        $this->assertEquals('island_fallback', $eta['source']);
        $this->assertEquals(4, $eta['eta_days']);
    }

    public function test_eta_guesses_mindanao_from_province_name(): void
    {
        $eta = $this->service->estimateEta('Lanao del Norte', null);

        $this->assertEquals('Mindanao', $eta['island']);
        $this->assertEquals('island_fallback', $eta['source']);
    }

    public function test_eta_defaults_to_luzon_for_unknown_province(): void
    {
        $eta = $this->service->estimateEta(null, null);

        $this->assertEquals('Luzon', $eta['island']);
    }

    public function test_eta_date_skips_sundays(): void
    {
        ShippingDay::create([
            'province' => 'METRO-MANILA',
            'city' => 'QUEZON-CITY',
            'barangay' => 'TEST',
            'shipping_days' => 2,
        ]);

        $eta = $this->service->estimateEta('Metro Manila', 'Quezon City');

        $etaDate = new \DateTime($eta['eta_date']);
        $dayOfWeek = (int) $etaDate->format('w');

        $this->assertNotEquals(0, $dayOfWeek, 'ETA date should not be a Sunday');
    }

    public function test_eta_returns_date_string(): void
    {
        ShippingDay::create([
            'province' => 'METRO-MANILA',
            'city' => 'QUEZON-CITY',
            'barangay' => 'TEST',
            'shipping_days' => 2,
        ]);

        $eta = $this->service->estimateEta('Metro Manila', 'Quezon City');

        $this->assertNotEmpty($eta['eta_date']);
        $parsed = \DateTime::createFromFormat('Y-m-d', $eta['eta_date']);
        $this->assertNotFalse($parsed);
    }

    public function test_eta_normalizes_province_name_with_spaces(): void
    {
        // Data stored with hyphens, query with spaces
        ShippingDay::create([
            'province' => 'AGUSAN-DEL-NORTE',
            'city' => 'BUTUAN-CITY',
            'barangay' => 'DAGUIOPAN',
            'shipping_days' => 6,
        ]);

        $eta = $this->service->estimateEta('Agusan del Norte', 'Butuan City');

        $this->assertEquals(6, $eta['eta_days']);
        $this->assertEquals('courier_table', $eta['source']);
    }
}
