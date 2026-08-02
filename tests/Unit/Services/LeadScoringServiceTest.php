<?php

namespace Tests\Unit\Services;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Models\Lead;
use App\Models\Customer;
use App\Models\LeadCycle;
use App\Models\User;
use App\Services\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean up pre-existing leads (PostgreSQL doesn't reset auto-increment on rollback)
        Lead::query()->delete();

        $this->service = new LeadScoringService;
    }

    public function test_referral_source_scores_higher_than_xlsx_import(): void
    {
        $referral = Lead::factory()->make(['source' => LeadSource::REFERRAL, 'customer_id' => null]);
        $xlsx = Lead::factory()->make(['source' => LeadSource::XLSX_IMPORT, 'customer_id' => null]);

        $referralScore = $this->service->score($referral);
        $xlsxScore = $this->service->score($xlsx);

        $this->assertGreaterThan($xlsxScore['source_score'], $referralScore['source_score']);
        $this->assertGreaterThan($xlsxScore['total'], $referralScore['total']);
    }

    public function test_complete_demographics_scores_higher_than_incomplete(): void
    {
        $complete = Lead::factory()->make([
            'source' => LeadSource::MANUAL,
            'customer_id' => null,
            'address' => '123 Main St',
            'city' => 'Manila',
            'state' => 'NCR',
            'barangay' => 'Barangay 1',
            'phone' => '+639171234567',
            'product_name' => 'Widget',
            'amount' => 500,
        ]);

        $incomplete = Lead::factory()->make([
            'source' => LeadSource::MANUAL,
            'customer_id' => null,
            'address' => null,
            'city' => null,
            'state' => null,
            'barangay' => null,
            'phone' => 'invalid',
            'product_name' => null,
            'amount' => null,
        ]);

        $completeScore = $this->service->score($complete);
        $incompleteScore = $this->service->score($incomplete);

        $this->assertGreaterThan($incompleteScore['demographic_score'], $completeScore['demographic_score']);
        $this->assertGreaterThan($incompleteScore['total'], $completeScore['total']);
    }

    public function test_blacklisted_customer_history_scores_zero(): void
    {
        $customer = Customer::create([
            'phone' => '+639171234567',
            'name' => 'Blacklisted Customer',
            'total_orders' => 5,
            'successful_orders' => 5,
            'success_rate' => 100,
            'is_blacklisted' => true,
        ]);

        $lead = Lead::factory()->create([
            'source' => LeadSource::MANUAL,
            'customer_id' => $customer->id,
        ]);

        $result = $this->service->score($lead->fresh(['customer']));

        $this->assertEquals(0, $result['history_score']);
    }

    public function test_repeat_customer_with_high_success_rate_scores_higher_than_new_customer(): void
    {
        $repeatCustomer = Customer::create([
            'phone' => '+639171111111',
            'name' => 'Repeat Buyer',
            'total_orders' => 5,
            'successful_orders' => 5,
            'success_rate' => 100,
            'is_blacklisted' => false,
        ]);

        $newCustomer = Customer::create([
            'phone' => '+639172222222',
            'name' => 'New Customer',
            'total_orders' => 0,
            'successful_orders' => 0,
            'success_rate' => 0,
            'is_blacklisted' => false,
        ]);

        $repeatLead = Lead::factory()->create(['customer_id' => $repeatCustomer->id]);
        $newLead = Lead::factory()->create(['customer_id' => $newCustomer->id]);

        $repeatScore = $this->service->score($repeatLead->fresh(['customer']));
        $newScore = $this->service->score($newLead->fresh(['customer']));

        $this->assertGreaterThan($newScore['history_score'], $repeatScore['history_score']);
    }

    public function test_score_from_import_data_uses_customer_history(): void
    {
        $customer = Customer::create([
            'phone' => '+639173333333',
            'name' => 'Existing Customer',
            'total_orders' => 3,
            'successful_orders' => 3,
            'success_rate' => 100,
            'is_blacklisted' => false,
        ]);

        $scoreWithHistory = $this->service->scoreFromImportData([
            'source' => 'DELIVERED_WAYBILL',
            'phone' => '+639173333333',
        ], $customer);

        $scoreWithoutHistory = $this->service->scoreFromImportData([
            'source' => 'DELIVERED_WAYBILL',
            'phone' => '+639173333333',
        ], null);

        $this->assertGreaterThan($scoreWithoutHistory, $scoreWithHistory);
    }

    public function test_rescore_lead_persists_quality_score_and_timestamp(): void
    {
        $lead = Lead::factory()->create([
            'source' => LeadSource::REFERRAL,
            'quality_score' => 0,
            'last_scored_at' => null,
        ]);

        $newScore = $this->service->rescoreLead($lead);

        $lead->refresh();
        $this->assertEquals($newScore, $lead->quality_score);
        $this->assertNotNull($lead->last_scored_at);
    }

    public function test_bulk_rescore_only_processes_stale_or_unscored_leads(): void
    {
        $stale = Lead::factory()->create(['last_scored_at' => now()->subDays(10)]);
        $fresh = Lead::factory()->create(['last_scored_at' => now()->subHours(1)]);
        $neverScored = Lead::factory()->create(['last_scored_at' => null]);

        $result = $this->service->bulkRescore(50);

        $this->assertEquals(2, $result['rescored']);
        $this->assertEquals($fresh->last_scored_at?->timestamp, $fresh->fresh()->last_scored_at?->timestamp);
        $this->assertNotNull($stale->fresh()->last_scored_at);
        $this->assertNotNull($neverScored->fresh()->last_scored_at);
    }

    public function test_lead_cycle_history_used_when_no_customer_linked(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create(['customer_id' => null]);

        LeadCycle::create([
            'lead_id' => $lead->id,
            'cycle_number' => 1,
            'assigned_agent_id' => $agent->id,
            'status' => 'CLOSED',
            'outcome' => 'ORDERED',
            'opened_at' => now()->subDays(2),
            'closed_at' => now()->subDay(),
        ]);

        LeadCycle::create([
            'lead_id' => $lead->id,
            'cycle_number' => 2,
            'assigned_agent_id' => $agent->id,
            'status' => 'CLOSED',
            'outcome' => 'NO_ANSWER',
            'opened_at' => now()->subDays(1),
            'closed_at' => now(),
        ]);

        $result = $this->service->score($lead->fresh(['cycles']));

        // 1 of 2 closed cycles resulted in a sale => 50
        $this->assertEquals(50, $result['history_score']);
    }
}
