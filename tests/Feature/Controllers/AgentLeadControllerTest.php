<?php

namespace Tests\Feature\Controllers;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Models\LeadCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentLeadControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create(['user_id' => $this->agent->id]);
        $this->seed(\Database\Seeders\RecyclingRulesSeeder::class);
    }

    public function test_index_returns_only_assigned_leads(): void
    {
        // Lead assigned to this agent
        $myLead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $this->agent->id,
        ]);

        // Lead assigned to another agent
        $otherAgent = User::factory()->create(['role' => 'agent']);
        Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $otherAgent->id,
        ]);

        $response = $this->actingAs($this->agent)
            ->getJson('/api/agent/leads');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $myLead->id);
    }

    public function test_index_does_not_include_phone(): void
    {
        Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $this->agent->id,
        ]);

        $response = $this->actingAs($this->agent)
            ->getJson('/api/agent/leads');

        $response->assertOk();
        $response->assertJsonMissingPath('data.0.phone');
    }

    public function test_call_returns_sip_link(): void
    {
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $this->agent->id,
        ]);
        LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $this->agent->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->agent)
            ->postJson("/api/agent/leads/{$lead->id}/call");

        $response->assertOk();
        $response->assertJsonPath('sip_link', 'sip:09171234567');
    }

    public function test_call_denied_for_unassigned_lead(): void
    {
        $otherAgent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $otherAgent->id,
        ]);

        $response = $this->actingAs($this->agent)
            ->postJson("/api/agent/leads/{$lead->id}/call");

        $response->assertForbidden();
    }

    public function test_outcome_closes_cycle(): void
    {
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $this->agent->id,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $this->agent->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->agent)
            ->postJson("/api/agent/leads/{$lead->id}/outcome", [
                'outcome' => 'NO_ANSWER',
                'remarks' => 'Tried 3 times',
            ]);

        $response->assertOk();

        $cycle->refresh();
        $this->assertEquals('CLOSED', $cycle->status);
        $this->assertEquals('NO_ANSWER', $cycle->outcome);
    }
}
