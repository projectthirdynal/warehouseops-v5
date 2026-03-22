<?php

namespace Tests\Feature\Controllers;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\User;
use App\Models\AgentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadPoolControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = User::factory()->create(['role' => 'supervisor']);
    }

    public function test_index_returns_pool_stats_and_leads(): void
    {
        Lead::factory()->count(5)->create(['pool_status' => PoolStatus::AVAILABLE]);
        Lead::factory()->count(3)->create(['pool_status' => PoolStatus::ASSIGNED]);

        $response = $this->actingAs($this->supervisor)
            ->get('/lead-pool');

        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('LeadPool/Index')
                ->has('stats')
                ->where('stats.available', 5)
                ->where('stats.assigned', 3)
        );
    }

    public function test_distribute_assigns_leads_to_agents(): void
    {
        $leads = Lead::factory()->count(10)->create(['pool_status' => PoolStatus::AVAILABLE]);
        $agents = User::factory()->count(2)->create(['role' => 'agent']);
        foreach ($agents as $agent) {
            AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true]);
        }

        $response = $this->actingAs($this->supervisor)
            ->post('/lead-pool/distribute', [
                'lead_ids' => $leads->pluck('id')->toArray(),
                'agent_ids' => $agents->pluck('id')->toArray(),
                'method' => 'equal',
            ]);

        $response->assertRedirect();

        foreach ($agents as $agent) {
            $this->assertEquals(5, Lead::where('assigned_to', $agent->id)->count());
        }
    }

    public function test_agents_cannot_access_lead_pool(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);

        $response = $this->actingAs($agent)
            ->get('/lead-pool');

        $response->assertForbidden();
    }
}
