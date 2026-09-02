<?php

namespace Tests\Feature\Controllers;

use Modules\Leads\Models\Lead;
use App\Models\AgentProfile;
use App\Models\LeadCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributionAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = User::factory()->create(['role' => 'supervisor']);
        $this->actingAs($this->supervisor);
    }

    public function test_analytics_page_loads(): void
    {
        $response = $this->get('/distribution/analytics');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('fairness')
            ->has('imbalanceAlerts')
            ->has('fairnessTrend')
        );
    }

    public function test_fairness_endpoint_returns_json(): void
    {
        $response = $this->getJson('/distribution/analytics/fairness');

        $response->assertOk();
        $response->assertJsonStructure([
            'gini',
            'total_assigned',
            'agent_count',
            'shares',
            'status',
        ]);
    }

    public function test_fairness_with_agents_returns_shares(): void
    {
        $agent1 = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $agent2 = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        AgentProfile::factory()->create([
            'user_id' => $agent1->id,
            'distribution_weight' => 1.0,
        ]);
        AgentProfile::factory()->create([
            'user_id' => $agent2->id,
            'distribution_weight' => 2.0,
        ]);

        $lead = Lead::factory()->create();
        LeadCycle::create([
            'lead_id' => $lead->id,
            'cycle_number' => 1,
            'assigned_agent_id' => $agent1->id,
            'status' => 'OPEN',
            'opened_at' => now(),
        ]);

        $response = $this->getJson('/distribution/analytics/fairness');

        $response->assertOk();
        $response->assertJsonPath('agent_count', 2);
        $response->assertJsonPath('total_assigned', 1);
    }

    public function test_imbalance_alerts_endpoint_returns_json(): void
    {
        $response = $this->getJson('/distribution/analytics/imbalance-alerts');

        $response->assertOk();
        $response->assertJsonStructure([]);
    }

    public function test_fairness_trend_endpoint_returns_json(): void
    {
        $response = $this->getJson('/distribution/analytics/fairness-trend?days=7');

        $response->assertOk();
        $response->assertJsonStructure([]);
    }

    public function test_rebalance_endpoint_requires_post(): void
    {
        $response = $this->postJson('/distribution/analytics/rebalance');

        $response->assertOk();
        $response->assertJsonStructure([
            'adjusted',
            'skipped',
            'details',
        ]);
    }

    public function test_rebalance_with_threshold_validation(): void
    {
        $response = $this->postJson('/distribution/analytics/rebalance', [
            'threshold' => 0.05,
        ]);

        $response->assertOk();
    }

    public function test_rebalance_rejects_invalid_threshold(): void
    {
        $response = $this->postJson('/distribution/analytics/rebalance', [
            'threshold' => 2.0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['threshold']);
    }

    public function test_alerts_endpoint_returns_json(): void
    {
        $response = $this->getJson('/distribution/analytics/alerts');

        $response->assertOk();
        $response->assertJsonStructure([
            'capacity_alerts',
            'backlog_alert',
            'queue_depth',
        ]);
    }

    public function test_rebalancing_report_endpoint_returns_json(): void
    {
        $response = $this->getJson('/distribution/analytics/rebalancing');

        $response->assertOk();
        $response->assertJsonStructure([
            'report',
        ]);
    }

    public function test_analytics_rejects_agent_role(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent);

        $response = $this->get('/distribution/analytics');

        $this->assertContains($response->status(), [403, 302]);
    }
}
