<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Models\LeadCycle;
use App\Models\User;
use App\Services\LeadAuditService;
use App\Services\LeadDistributionService;
use App\Services\LeadPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadDistributionServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadDistributionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new LeadAuditService();
        $poolService = new LeadPoolService($auditService);
        $this->service = new LeadDistributionService($poolService, $auditService);
    }

    public function test_distribute_equal_splits_leads_evenly(): void
    {
        $leads = Lead::factory()->count(20)->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agents = User::factory()->count(4)->create(['role' => 'agent']);
        foreach ($agents as $agent) {
            AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        }

        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $result = $this->service->distributeEqual(
            leadIds: $leads->pluck('id')->toArray(),
            agentIds: $agents->pluck('id')->toArray(),
            supervisorId: $supervisor->id
        );

        $this->assertEquals(20, $result['total_distributed']);
        $this->assertEquals(4, $result['agent_count']);
        $this->assertEquals(5, $result['per_agent']);

        // Each agent should have 5 assigned leads
        foreach ($agents as $agent) {
            $assigned = Lead::where('assigned_to', $agent->id)->count();
            $this->assertEquals(5, $assigned);
        }
    }

    public function test_distribute_custom_assigns_specified_counts(): void
    {
        $leads = Lead::factory()->count(30)->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agents = User::factory()->count(3)->create(['role' => 'agent']);
        foreach ($agents as $agent) {
            AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        }

        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $distribution = [
            $agents[0]->id => 10,
            $agents[1]->id => 15,
            $agents[2]->id => 5,
        ];

        $result = $this->service->distributeCustom(
            leadIds: $leads->pluck('id')->toArray(),
            distribution: $distribution,
            supervisorId: $supervisor->id
        );

        $this->assertEquals(30, $result['total_distributed']);

        $this->assertEquals(10, Lead::where('assigned_to', $agents[0]->id)->count());
        $this->assertEquals(15, Lead::where('assigned_to', $agents[1]->id)->count());
        $this->assertEquals(5, Lead::where('assigned_to', $agents[2]->id)->count());
    }

    public function test_distribution_creates_lead_cycles(): void
    {
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $this->service->distributeEqual(
            leadIds: [$lead->id],
            agentIds: [$agent->id],
            supervisorId: $supervisor->id
        );

        $this->assertDatabaseHas('lead_cycles', [
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_distribution_updates_lead_pool_status(): void
    {
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $this->service->distributeEqual(
            leadIds: [$lead->id],
            agentIds: [$agent->id],
            supervisorId: $supervisor->id
        );

        $lead->refresh();
        $this->assertEquals(PoolStatus::ASSIGNED, $lead->pool_status);
        $this->assertEquals($agent->id, $lead->assigned_to);
        $this->assertNotNull($lead->assigned_at);
    }

    public function test_distribute_equal_handles_uneven_distribution(): void
    {
        // 11 leads, 3 agents = 3, 4, 4 (with remainder distributed)
        $leads = Lead::factory()->count(11)->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agents = User::factory()->count(3)->create(['role' => 'agent']);
        foreach ($agents as $agent) {
            AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        }

        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $result = $this->service->distributeEqual(
            leadIds: $leads->pluck('id')->toArray(),
            agentIds: $agents->pluck('id')->toArray(),
            supervisorId: $supervisor->id
        );

        $this->assertEquals(11, $result['total_distributed']);

        // Check that all 11 leads are distributed
        $totalAssigned = Lead::whereNotNull('assigned_to')->count();
        $this->assertEquals(11, $totalAssigned);
    }

    public function test_distribution_increments_total_cycles(): void
    {
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::AVAILABLE,
            'total_cycles' => 0,
        ]);
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $this->service->distributeEqual(
            leadIds: [$lead->id],
            agentIds: [$agent->id],
            supervisorId: $supervisor->id
        );

        $lead->refresh();
        $this->assertEquals(1, $lead->total_cycles);
    }

    public function test_distribution_only_processes_available_leads(): void
    {
        $availableLead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);
        $assignedLead = Lead::factory()->create(['pool_status' => PoolStatus::ASSIGNED]);
        $cooldownLead = Lead::factory()->create(['pool_status' => PoolStatus::COOLDOWN]);

        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $result = $this->service->distributeEqual(
            leadIds: [$availableLead->id, $assignedLead->id, $cooldownLead->id],
            agentIds: [$agent->id],
            supervisorId: $supervisor->id
        );

        // Only the available lead should be distributed
        $this->assertEquals(1, $result['total_distributed']);
        $this->assertEquals($agent->id, $availableLead->fresh()->assigned_to);
        $this->assertNull($assignedLead->fresh()->assigned_to);
        $this->assertNull($cooldownLead->fresh()->assigned_to);
    }

    public function test_get_available_agents_returns_active_available_agents(): void
    {
        $availableAgent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        AgentProfile::factory()->create(['user_id' => $availableAgent->id, 'is_available' => true]);

        $unavailableAgent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        AgentProfile::factory()->create(['user_id' => $unavailableAgent->id, 'is_available' => false]);

        $inactiveAgent = User::factory()->create(['role' => 'agent', 'is_active' => false]);
        AgentProfile::factory()->create(['user_id' => $inactiveAgent->id, 'is_available' => true]);

        $agents = $this->service->getAvailableAgents();

        $this->assertCount(1, $agents);
        $this->assertEquals($availableAgent->id, $agents->first()->id);
    }
}
