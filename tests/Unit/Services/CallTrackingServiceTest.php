<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;
use App\Services\CallTrackingService;
use App\Services\LeadAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private CallTrackingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CallTrackingService(new LeadAuditService());
    }

    public function test_initiate_call_returns_sip_link(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
        ]);

        $sipLink = $this->service->initiateCall($lead, $cycle, $agent);

        $this->assertEquals('sip:09171234567', $sipLink);
    }

    public function test_initiate_call_increments_call_count(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
            'call_count' => 0,
        ]);

        $this->service->initiateCall($lead, $cycle, $agent);

        $cycle->refresh();
        $this->assertEquals(1, $cycle->call_count);
        $this->assertNotNull($cycle->last_call_at);
    }

    public function test_initiate_call_logs_audit(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->service->initiateCall($lead, $cycle, $agent);

        $this->assertDatabaseHas('lead_pool_audit', [
            'lead_id' => $lead->id,
            'user_id' => $agent->id,
            'action' => 'CALL_INITIATED',
        ]);
    }

    public function test_initiate_call_updates_lead_call_attempts(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
            'call_attempts' => 0,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->service->initiateCall($lead, $cycle, $agent);

        $lead->refresh();
        $this->assertEquals(1, $lead->call_attempts);
        $this->assertNotNull($lead->last_called_at);
    }
}
