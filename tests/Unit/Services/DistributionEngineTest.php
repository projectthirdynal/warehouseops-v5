<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Models\AgentWorkload;
use App\Models\DistributionRule;
use App\Models\User;
use App\Services\AgentAvailability;
use App\Services\CapacityManager;
use App\Services\DistributionEngine;
use App\Services\LeadAuditService;
use App\Services\LeadPoolService;
use App\Services\RuleConditionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DistributionEngineTest extends TestCase
{
    use RefreshDatabase;

    private DistributionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean up any pre-existing rules from the database (PostgreSQL doesn't reset auto-increment on rollback)
        DistributionRule::query()->delete();

        $capacityManager = $this->createMock(CapacityManager::class);
        $capacityManager->method('canAcceptLead')->willReturn(true);

        $auditService = $this->createMock(LeadAuditService::class);
        $poolService = $this->createMock(LeadPoolService::class);
        $agentAvailability = new AgentAvailability;
        $conditionEvaluator = new RuleConditionEvaluator;

        $this->engine = new DistributionEngine(
            $capacityManager,
            $agentAvailability,
            $poolService,
            $auditService,
            $conditionEvaluator
        );
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_find_best_agent_skips_rule_when_lead_quality_score_below_min(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create([
            'user_id' => $agent->id,
            'is_available' => true,
            'auto_assign_enabled' => true,
        ]);

        // Rule with min_quality_score=80 — should be skipped for a lead with score=50
        $strictRule = DistributionRule::factory()->create([
            'priority' => 1,
            'filters' => ['conditions' => ['min_quality_score' => 80]],
        ]);

        // Fallback rule with no conditions — should match
        $fallbackRule = DistributionRule::factory()->create([
            'priority' => 2,
            'filters' => null,
        ]);

        $lead = Lead::factory()->create([
            'quality_score' => 50,
            'pool_status' => PoolStatus::AVAILABLE,
        ]);

        $result = $this->engine->findBestAgent($lead);

        // Should match the fallback rule, not the strict rule
        $this->assertNotNull($result['agent_id']);
        $this->assertEquals($fallbackRule->id, $result['rule_id']);
        $this->assertNotEquals($strictRule->id, $result['rule_id']);
    }

    public function test_find_best_agent_matches_rule_when_quality_score_meets_condition(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create([
            'user_id' => $agent->id,
            'is_available' => true,
            'auto_assign_enabled' => true,
        ]);

        $matchingRule = DistributionRule::factory()->create([
            'priority' => 1,
            'filters' => ['conditions' => ['min_quality_score' => 70]],
        ]);

        $lead = Lead::factory()->create([
            'quality_score' => 85,
            'pool_status' => PoolStatus::AVAILABLE,
        ]);

        $result = $this->engine->findBestAgent($lead);

        $this->assertNotNull($result['agent_id']);
        $this->assertEquals($matchingRule->id, $result['rule_id']);
    }

    public function test_find_best_agent_skips_rule_when_lead_region_does_not_match(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create([
            'user_id' => $agent->id,
            'is_available' => true,
            'auto_assign_enabled' => true,
        ]);

        // Rule requiring Metro Manila region — lead is in Cebu
        $strictRule = DistributionRule::factory()->create([
            'priority' => 1,
            'filters' => ['conditions' => ['lead_regions' => ['Metro Manila']]],
        ]);

        // Fallback rule — no conditions
        $fallbackRule = DistributionRule::factory()->create([
            'priority' => 2,
            'filters' => null,
        ]);

        $lead = Lead::factory()->create([
            'state' => 'Cebu',
            'pool_status' => PoolStatus::AVAILABLE,
        ]);

        $result = $this->engine->findBestAgent($lead);

        $this->assertNotNull($result['agent_id']);
        $this->assertEquals($fallbackRule->id, $result['rule_id']);
        $this->assertNotEquals($strictRule->id, $result['rule_id']);
    }

    public function test_find_best_agent_skips_rule_when_lead_source_does_not_match(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create([
            'user_id' => $agent->id,
            'is_available' => true,
            'auto_assign_enabled' => true,
        ]);

        $strictRule = DistributionRule::factory()->create([
            'priority' => 1,
            'filters' => ['conditions' => ['lead_sources' => ['WAYBILL']]],
        ]);

        $fallbackRule = DistributionRule::factory()->create([
            'priority' => 2,
            'filters' => null,
        ]);

        $lead = Lead::factory()->create([
            'source' => LeadSource::MANUAL,
            'pool_status' => PoolStatus::AVAILABLE,
        ]);

        $result = $this->engine->findBestAgent($lead);

        $this->assertNotNull($result['agent_id']);
        $this->assertEquals($fallbackRule->id, $result['rule_id']);
        $this->assertNotEquals($strictRule->id, $result['rule_id']);
    }

    public function test_find_best_agent_falls_through_all_rules_when_none_match(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create([
            'user_id' => $agent->id,
            'is_available' => true,
            'auto_assign_enabled' => true,
        ]);

        // Single rule with impossible condition
        DistributionRule::factory()->create([
            'priority' => 1,
            'filters' => ['conditions' => ['min_quality_score' => 200]],
        ]);

        $lead = Lead::factory()->create([
            'quality_score' => 50,
            'pool_status' => PoolStatus::AVAILABLE,
        ]);

        $result = $this->engine->findBestAgent($lead);

        // No rule matched, but fallback round-robin should still find the agent
        $this->assertNotNull($result['agent_id']);
        $this->assertNull($result['rule_id']);
    }

    public function test_find_best_agent_uses_priority_order_for_condition_matching(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create([
            'user_id' => $agent->id,
            'is_available' => true,
            'auto_assign_enabled' => true,
        ]);

        // High-priority rule for high-quality leads
        $highPriorityRule = DistributionRule::factory()->create([
            'priority' => 1,
            'filters' => ['conditions' => ['min_quality_score' => 80]],
        ]);

        // Lower-priority rule for medium-quality leads
        $lowPriorityRule = DistributionRule::factory()->create([
            'priority' => 2,
            'filters' => ['conditions' => ['min_quality_score' => 50]],
        ]);

        // Lead with score 85 should match the first rule (priority 1)
        $lead = Lead::factory()->create([
            'quality_score' => 85,
            'pool_status' => PoolStatus::AVAILABLE,
        ]);

        $result = $this->engine->findBestAgent($lead);

        $this->assertNotNull($result['agent_id']);
        $this->assertEquals($highPriorityRule->id, $result['rule_id']);
    }

    public function test_find_best_agent_skips_to_lower_priority_rule_when_higher_does_not_match(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        AgentProfile::factory()->create([
            'user_id' => $agent->id,
            'is_available' => true,
            'auto_assign_enabled' => true,
        ]);

        // High-priority rule requiring high quality score
        $highPriorityRule = DistributionRule::factory()->create([
            'priority' => 1,
            'filters' => ['conditions' => ['min_quality_score' => 80]],
        ]);

        // Lower-priority rule for medium quality
        $lowPriorityRule = DistributionRule::factory()->create([
            'priority' => 2,
            'filters' => ['conditions' => ['min_quality_score' => 40]],
        ]);

        // Lead with score 60 should skip rule 1 and match rule 2
        $lead = Lead::factory()->create([
            'quality_score' => 60,
            'pool_status' => PoolStatus::AVAILABLE,
        ]);

        $result = $this->engine->findBestAgent($lead);

        $this->assertNotNull($result['agent_id']);
        $this->assertEquals($lowPriorityRule->id, $result['rule_id']);
        $this->assertNotEquals($highPriorityRule->id, $result['rule_id']);
    }
}
