<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\User;
use App\Services\LeadAuditService;
use App\Services\LeadPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadPoolServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadPoolService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LeadPoolService(new LeadAuditService());
    }

    public function test_get_available_leads_returns_only_available(): void
    {
        Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);
        Lead::factory()->create(['pool_status' => PoolStatus::ASSIGNED]);
        Lead::factory()->create(['pool_status' => PoolStatus::COOLDOWN]);

        $available = $this->service->getAvailableLeads();

        $this->assertCount(1, $available);
        $this->assertEquals(PoolStatus::AVAILABLE, $available->first()->pool_status);
    }

    public function test_mark_as_assigned_changes_status(): void
    {
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agent = User::factory()->create(['role' => 'agent']);

        $this->service->markAsAssigned($lead, $agent);

        $lead->refresh();
        $this->assertEquals(PoolStatus::ASSIGNED, $lead->pool_status);
        $this->assertEquals($agent->id, $lead->assigned_to);
    }

    public function test_mark_as_cooldown_sets_cooldown_until(): void
    {
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::ASSIGNED]);

        $this->service->markAsCooldown($lead, 24);

        $lead->refresh();
        $this->assertEquals(PoolStatus::COOLDOWN, $lead->pool_status);
        $this->assertNotNull($lead->cooldown_until);
        $this->assertTrue($lead->cooldown_until->isFuture());
    }

    public function test_mark_as_exhausted_changes_status(): void
    {
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::ASSIGNED]);

        $this->service->markAsExhausted($lead);

        $lead->refresh();
        $this->assertEquals(PoolStatus::EXHAUSTED, $lead->pool_status);
        $this->assertTrue($lead->is_exhausted);
    }

    public function test_get_pool_stats_returns_counts(): void
    {
        Lead::factory()->count(3)->create(['pool_status' => PoolStatus::AVAILABLE]);
        Lead::factory()->count(2)->create(['pool_status' => PoolStatus::ASSIGNED]);
        Lead::factory()->count(1)->create(['pool_status' => PoolStatus::COOLDOWN]);
        Lead::factory()->count(1)->create(['pool_status' => PoolStatus::EXHAUSTED]);

        $stats = $this->service->getPoolStats();

        $this->assertEquals(3, $stats['available']);
        $this->assertEquals(2, $stats['assigned']);
        $this->assertEquals(1, $stats['cooldown']);
        $this->assertEquals(1, $stats['exhausted']);
    }

    public function test_mark_as_available_clears_assignment(): void
    {
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->addHours(24),
            'assigned_to' => 1,
        ]);

        $this->service->markAsAvailable($lead);

        $lead->refresh();
        $this->assertEquals(PoolStatus::AVAILABLE, $lead->pool_status);
        $this->assertNull($lead->cooldown_until);
        $this->assertNull($lead->assigned_to);
    }
}
