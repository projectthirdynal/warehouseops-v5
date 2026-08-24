<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\LeadPoolStatus;
use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Enums\PoolMemberStatus;
use App\Domain\Lead\Enums\PoolRequestStatus;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadPool;
use App\Domain\Lead\Models\LeadPoolMember;
use App\Domain\Lead\Models\TelesalesBrandConfig;
use App\Domain\Shop\Models\AddressMapping;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Waybill;
use App\Services\PoolRequestService;
use App\Services\PoolReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoolRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private PoolRequestService $requestService;

    private PoolReservationService $reservationService;

    private User $admin;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestService = app(PoolRequestService::class);
        $this->reservationService = app(PoolReservationService::class);

        SystemSetting::set('telesales_max_waybill_age_days', 60, 'telesales', 'int');

        $this->admin = User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
        $this->supervisor = User::factory()->create(['role' => 'supervisor', 'is_active' => true]);

        TelesalesBrandConfig::create([
            'brand_name' => 'Black Garlic',
            'display_name' => 'Black Garlic',
            'is_active' => true,
            'match_patterns' => ['Black Garlic'],
            'priority' => 0,
        ]);
    }

    // ─── Request Creation ──────────────────────────────────────────────

    public function test_create_request_stores_filters_and_availability_snapshot(): void
    {
        $this->createEligibleLead('Black Garlic Coffee', 'NCR');

        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'business_region' => 'NCR',
            'lead_age_from' => 0,
            'lead_age_to' => 60,
            'requested_quantity' => 10,
            'distribution_method' => 'equal',
        ], $this->supervisor);

        $this->assertSame(PoolRequestStatus::PENDING_APPROVAL, $request->status);
        $this->assertSame('Black Garlic', $request->brand_name);
        $this->assertSame('NCR', $request->business_region);
        $this->assertSame(10, $request->requested_quantity);
        $this->assertSame(1, $request->available_quantity_at_request);
        $this->assertNotEmpty($request->request_number);
    }

    public function test_request_number_is_sequential_per_day(): void
    {
        $req1 = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'requested_quantity' => 1,
        ], $this->supervisor);

        $req2 = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'requested_quantity' => 1,
        ], $this->supervisor);

        $this->assertNotSame($req1->request_number, $req2->request_number);
    }

    // ─── Approval ──────────────────────────────────────────────────────

    public function test_approve_request_creates_pool_and_reserves_leads(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createEligibleLead('Black Garlic Coffee', 'NCR');
        }

        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'business_region' => 'NCR',
            'requested_quantity' => 3,
        ], $this->supervisor);

        $result = $this->requestService->approveRequest($request, $this->admin, 3);

        $this->assertSame(PoolRequestStatus::APPROVED, $result['request']->status);
        $this->assertSame(3, $result['reserved']);
        $this->assertInstanceOf(LeadPool::class, $result['pool']);
        $this->assertSame(LeadPoolStatus::ACTIVE, $result['pool']->status);
        $this->assertSame(3, $result['pool']->reserved_quantity);

        $members = LeadPoolMember::where('lead_pool_id', $result['pool']->id)->get();
        $this->assertCount(3, $members);
        $this->assertTrue($members->every(fn ($m) => $m->status === PoolMemberStatus::PENDING));
    }

    public function test_approve_with_modified_quantity_reserves_correct_amount(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createEligibleLead('Black Garlic Coffee', 'NCR');
        }

        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'business_region' => 'NCR',
            'requested_quantity' => 5,
        ], $this->supervisor);

        $result = $this->requestService->approveRequest($request, $this->admin, 2);

        $this->assertSame(2, $result['reserved']);
        $this->assertSame(2, $result['pool']->reserved_quantity);
        $this->assertSame(2, $result['request']->approved_quantity);
    }

    public function test_approve_throws_if_not_pending(): void
    {
        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'requested_quantity' => 1,
        ], $this->supervisor);

        $request->update(['status' => PoolRequestStatus::REJECTED]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not pending approval');

        $this->requestService->approveRequest($request, $this->admin);
    }

    public function test_approve_throws_if_insufficient_leads(): void
    {
        $this->createEligibleLead('Black Garlic Coffee', 'NCR');

        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'business_region' => 'NCR',
            'requested_quantity' => 10,
        ], $this->supervisor);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient');

        $this->requestService->approveRequest($request, $this->admin, 10);
    }

    // ─── Rejection ─────────────────────────────────────────────────────

    public function test_reject_request_sets_rejection_fields(): void
    {
        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'requested_quantity' => 1,
        ], $this->supervisor);

        $result = $this->requestService->rejectRequest($request, $this->admin, 'Not enough inventory');

        $this->assertSame(PoolRequestStatus::REJECTED, $result->status);
        $this->assertSame($this->admin->id, $result->rejected_by);
        $this->assertNotNull($result->rejected_at);
        $this->assertSame('Not enough inventory', $result->rejection_reason);
    }

    // ─── Cancellation ──────────────────────────────────────────────────

    public function test_cancel_pending_request(): void
    {
        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'requested_quantity' => 1,
        ], $this->supervisor);

        $result = $this->requestService->cancelRequest($request, $this->supervisor, 'No longer needed');

        $this->assertSame(PoolRequestStatus::CANCELLED, $result->status);
    }

    public function test_cancel_approved_request_throws(): void
    {
        $this->createEligibleLead('Black Garlic Coffee', 'NCR');

        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'requested_quantity' => 1,
        ], $this->supervisor);

        $this->requestService->approveRequest($request, $this->admin, 1);

        $this->expectException(\RuntimeException::class);
        $this->requestService->cancelRequest($request, $this->supervisor);
    }

    // ─── Recalculation ─────────────────────────────────────────────────

    public function test_recalculate_availability_returns_live_count(): void
    {
        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'business_region' => 'NCR',
            'requested_quantity' => 1,
        ], $this->supervisor);

        // Initially 0 available
        $this->assertSame(0, $this->requestService->recalculateAvailability($request));

        // Add a lead
        $this->createEligibleLead('Black Garlic Coffee', 'NCR');

        $this->assertSame(1, $this->requestService->recalculateAvailability($request));
    }

    // ─── Pool Member Assignment ────────────────────────────────────────

    public function test_mark_member_assigned_updates_pool_and_request_status(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createEligibleLead('Black Garlic Coffee', 'NCR');
        }

        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'business_region' => 'NCR',
            'requested_quantity' => 3,
        ], $this->supervisor);

        $result = $this->requestService->approveRequest($request, $this->admin, 3);
        $pool = $result['pool'];

        $member = $pool->members()->first();
        $this->reservationService->markMemberAssigned($member);

        $this->assertSame(PoolMemberStatus::ASSIGNED, $member->fresh()->status);
        $this->assertSame(1, $pool->fresh()->distributed_quantity);
        $this->assertSame(LeadPoolStatus::PARTIALLY_DISTRIBUTED, $pool->fresh()->status);
        $this->assertSame(PoolRequestStatus::PARTIALLY_DISTRIBUTED, $request->fresh()->status);
    }

    public function test_all_members_assigned_marks_pool_fully_distributed(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->createEligibleLead('Black Garlic Coffee', 'NCR');
        }

        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'business_region' => 'NCR',
            'requested_quantity' => 2,
        ], $this->supervisor);

        $result = $this->requestService->approveRequest($request, $this->admin, 2);
        $pool = $result['pool'];

        foreach ($pool->members as $member) {
            $this->reservationService->markMemberAssigned($member);
        }

        $this->assertSame(LeadPoolStatus::FULLY_DISTRIBUTED, $pool->fresh()->status);
        $this->assertSame(PoolRequestStatus::DISTRIBUTED, $request->fresh()->status);
    }

    // ─── Pool Cancellation ─────────────────────────────────────────────

    public function test_cancel_pool_removes_pending_members(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createEligibleLead('Black Garlic Coffee', 'NCR');
        }

        $request = $this->requestService->createRequest([
            'brand_name' => 'Black Garlic',
            'business_region' => 'NCR',
            'requested_quantity' => 3,
        ], $this->supervisor);

        $result = $this->requestService->approveRequest($request, $this->admin, 3);
        $pool = $result['pool'];

        $this->reservationService->cancelPool($pool, $this->admin, 'Test cancellation');

        $this->assertSame(LeadPoolStatus::CANCELLED, $pool->fresh()->status);
        $pendingCount = $pool->members()->where('status', PoolMemberStatus::PENDING)->count();
        $this->assertSame(0, $pendingCount);
        $removedCount = $pool->members()->where('status', PoolMemberStatus::REMOVED)->count();
        $this->assertSame(3, $removedCount);
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function createEligibleLead(string $productName, string $businessRegion): Lead
    {
        $mapping = AddressMapping::firstOrCreate(
            ['country' => 'PH', 'province' => 'TestProvince', 'city_municipality' => 'TestCity'],
            ['region' => 'TestRegion', 'business_region' => $businessRegion]
        );

        $customer = Customer::factory()->create([
            'is_blacklisted' => false,
            'do_not_call' => false,
        ]);

        $waybill = Waybill::factory()->create([
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(10),
        ]);

        return Lead::factory()->create([
            'customer_id' => $customer->id,
            'pool_status' => PoolStatus::AVAILABLE,
            'source' => LeadSource::DELIVERED_WAYBILL,
            'source_waybill_id' => $waybill->id,
            'address_mapping_id' => $mapping->id,
            'product_name' => $productName,
            'is_exhausted' => false,
            'phone' => '09'.fake()->numberBetween(100000000, 999999999),
        ]);
    }
}
