<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Models\Lead;
use App\Models\LeadPoolAudit;
use App\Models\User;
use App\Services\LeadAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LeadAuditService();
    }

    public function test_logs_action_with_user(): void
    {
        $user = User::factory()->create();
        $lead = Lead::factory()->create();

        $this->service->log(
            lead: $lead,
            action: 'CALL_INITIATED',
            user: $user
        );

        $this->assertDatabaseHas('lead_pool_audit', [
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'action' => 'CALL_INITIATED',
        ]);
    }

    public function test_logs_action_with_old_and_new_values(): void
    {
        $lead = Lead::factory()->create();

        $this->service->log(
            lead: $lead,
            action: 'POOL_STATUS_CHANGED',
            oldValue: 'AVAILABLE',
            newValue: 'ASSIGNED'
        );

        $this->assertDatabaseHas('lead_pool_audit', [
            'lead_id' => $lead->id,
            'action' => 'POOL_STATUS_CHANGED',
            'old_value' => 'AVAILABLE',
            'new_value' => 'ASSIGNED',
        ]);
    }

    public function test_logs_action_with_metadata(): void
    {
        $lead = Lead::factory()->create();

        $this->service->log(
            lead: $lead,
            action: 'DISTRIBUTED',
            metadata: ['batch_size' => 20, 'agent_count' => 5]
        );

        $audit = LeadPoolAudit::where('lead_id', $lead->id)->first();

        $this->assertEquals(['batch_size' => 20, 'agent_count' => 5], $audit->metadata);
    }
}
