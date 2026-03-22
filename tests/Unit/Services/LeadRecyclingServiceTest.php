<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\LeadOutcome;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;
use App\Services\LeadAuditService;
use App\Services\LeadPoolService;
use App\Services\LeadRecyclingService;
use Database\Seeders\RecyclingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadRecyclingServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadRecyclingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new LeadAuditService;
        $poolService = new LeadPoolService($auditService);
        $this->service = new LeadRecyclingService($poolService, $auditService);

        // Seed default rules
        $this->seed(RecyclingRulesSeeder::class);
    }

    public function test_process_outcome_moves_to_cooldown_for_no_answer(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
            'total_cycles' => 1,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
        ]);

        $this->service->processOutcome($lead, $cycle, LeadOutcome::NO_ANSWER, $agent);

        $lead->refresh();
        $cycle->refresh();

        $this->assertEquals(PoolStatus::COOLDOWN, $lead->pool_status);
        $this->assertEquals('CLOSED', $cycle->status);
        $this->assertEquals('NO_ANSWER', $cycle->outcome);
    }

    public function test_process_outcome_exhausts_lead_at_max_cycles(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
            'total_cycles' => 5, // At max for NO_ANSWER
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
        ]);

        $this->service->processOutcome($lead, $cycle, LeadOutcome::NO_ANSWER, $agent);

        $lead->refresh();
        $this->assertEquals(PoolStatus::EXHAUSTED, $lead->pool_status);
        $this->assertTrue($lead->is_exhausted);
    }

    public function test_process_outcome_wrong_number_exhausts_immediately(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::ASSIGNED,
            'assigned_to' => $agent->id,
            'total_cycles' => 1,
        ]);
        $cycle = LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
        ]);

        $this->service->processOutcome($lead, $cycle, LeadOutcome::WRONG_NUMBER, $agent);

        $lead->refresh();
        $this->assertEquals(PoolStatus::EXHAUSTED, $lead->pool_status);
    }

    public function test_process_expired_cooldowns_moves_to_available(): void
    {
        // Create lead with expired cooldown
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::COOLDOWN,
            'cooldown_until' => now()->subHour(),
            'total_cycles' => 1,
        ]);

        $processed = $this->service->processExpiredCooldowns();

        $lead->refresh();
        $this->assertEquals(PoolStatus::AVAILABLE, $lead->pool_status);
        $this->assertEquals(1, $processed);
    }

    public function test_revive_lead_moves_exhausted_to_available(): void
    {
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $lead = Lead::factory()->create([
            'pool_status' => PoolStatus::EXHAUSTED,
            'is_exhausted' => true,
        ]);

        $this->service->reviveLead($lead, $supervisor);

        $lead->refresh();
        $this->assertEquals(PoolStatus::AVAILABLE, $lead->pool_status);
    }
}
