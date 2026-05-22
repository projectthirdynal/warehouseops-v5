<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\FraudFlag;
use App\Models\LeadCycle;
use App\Models\User;
use App\Services\FraudDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FraudDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FraudDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FraudDetectionService();
    }

    public function test_detect_suspicious_velocity_creates_flag_for_high_outcome_count(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);

        // Create 10 cycles with same outcome in last 30 minutes
        for ($i = 0; $i < 10; $i++) {
            LeadCycle::factory()->create([
                'lead_id' => $lead->id,
                'assigned_agent_id' => $agent->id,
                'outcome' => 'NO_ANSWER',
                'status' => 'CLOSED',
                'closed_at' => now()->subMinutes(5),
            ]);
        }

        $flags = $this->service->detectSuspiciousVelocity(threshold: 10, minutes: 30);

        $this->assertCount(1, $flags);
        $this->assertEquals('SUSPICIOUS_VELOCITY', $flags->first()->flag_type);
        $this->assertEquals('WARNING', $flags->first()->severity);
        $this->assertEquals($agent->id, $flags->first()->agent_id);
    }

    public function test_detect_suspicious_velocity_ignores_below_threshold(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);

        // Create only 5 cycles (below threshold)
        for ($i = 0; $i < 5; $i++) {
            LeadCycle::factory()->create([
                'lead_id' => $lead->id,
                'assigned_agent_id' => $agent->id,
                'outcome' => 'NO_ANSWER',
                'status' => 'CLOSED',
                'closed_at' => now()->subMinutes(5),
            ]);
        }

        $flags = $this->service->detectSuspiciousVelocity(threshold: 10, minutes: 30);

        $this->assertCount(0, $flags);
    }

    public function test_detect_no_call_outcomes_creates_critical_flag(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);

        // Create cycle with outcome but no calls
        LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'outcome' => 'NOT_INTERESTED',
            'call_count' => 0,
            'status' => 'CLOSED',
            'closed_at' => now(),
        ]);

        $flags = $this->service->detectNoCallOutcomes();

        $this->assertCount(1, $flags);
        $this->assertEquals('NO_CALL_INITIATED', $flags->first()->flag_type);
        $this->assertEquals('CRITICAL', $flags->first()->severity);
        $this->assertEquals($lead->id, $flags->first()->lead_id);
    }

    public function test_detect_no_call_outcomes_ignores_cycles_with_calls(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);

        // Create cycle with outcome AND calls
        LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'outcome' => 'NOT_INTERESTED',
            'call_count' => 3,
            'status' => 'CLOSED',
            'closed_at' => now(),
        ]);

        $flags = $this->service->detectNoCallOutcomes();

        $this->assertCount(0, $flags);
    }

    public function test_detect_lead_hoarding_flags_old_uncalled_leads(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::ASSIGNED]);

        // Create active cycle with no calls, opened 25 hours ago
        LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
            'call_count' => 0,
            'opened_at' => now()->subHours(25),
        ]);

        $flags = $this->service->detectLeadHoarding(hoursThreshold: 24);

        $this->assertCount(1, $flags);
        $this->assertEquals('LEAD_HOARDING', $flags->first()->flag_type);
        $this->assertEquals('WARNING', $flags->first()->severity);
    }

    public function test_detect_lead_hoarding_ignores_recently_assigned(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::ASSIGNED]);

        // Create active cycle with no calls, opened 5 hours ago
        LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
            'call_count' => 0,
            'opened_at' => now()->subHours(5),
        ]);

        $flags = $this->service->detectLeadHoarding(hoursThreshold: 24);

        $this->assertCount(0, $flags);
    }

    public function test_run_all_detections_returns_combined_results(): void
    {
        $results = $this->service->runAllDetections();

        $this->assertArrayHasKey('suspicious_velocity', $results);
        $this->assertArrayHasKey('no_call_outcomes', $results);
        $this->assertArrayHasKey('lead_hoarding', $results);
    }

    public function test_does_not_create_duplicate_flags(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create(['pool_status' => PoolStatus::AVAILABLE]);

        // Create cycle with outcome but no calls
        LeadCycle::factory()->create([
            'lead_id' => $lead->id,
            'assigned_agent_id' => $agent->id,
            'outcome' => 'NOT_INTERESTED',
            'call_count' => 0,
            'status' => 'CLOSED',
            'closed_at' => now(),
        ]);

        // Run detection twice
        $firstRun = $this->service->detectNoCallOutcomes();
        $secondRun = $this->service->detectNoCallOutcomes();

        $this->assertCount(1, $firstRun);
        $this->assertCount(0, $secondRun);
        $this->assertEquals(1, FraudFlag::count());
    }

    public function test_get_unreviewed_flags_returns_only_unreviewed(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $reviewer = User::factory()->create(['role' => 'supervisor']);

        // Create reviewed flag
        FraudFlag::create([
            'agent_id' => $agent->id,
            'flag_type' => 'SUSPICIOUS_VELOCITY',
            'severity' => 'WARNING',
            'is_reviewed' => true,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        // Create unreviewed flag
        FraudFlag::create([
            'agent_id' => $agent->id,
            'flag_type' => 'NO_CALL_INITIATED',
            'severity' => 'CRITICAL',
            'is_reviewed' => false,
        ]);

        $unreviewed = $this->service->getUnreviewedFlags();

        $this->assertCount(1, $unreviewed);
        $this->assertEquals('NO_CALL_INITIATED', $unreviewed->first()->flag_type);
    }
}
