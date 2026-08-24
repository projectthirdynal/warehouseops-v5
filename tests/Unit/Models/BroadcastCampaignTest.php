<?php

namespace Tests\Unit\Models;

use App\Domain\Shop\Models\BroadcastCampaign;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BroadcastCampaignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeCampaign(array $attributes = []): BroadcastCampaign
    {
        return (new BroadcastCampaign)->forceFill(array_merge([
            'name' => 'Test Campaign',
            'status' => BroadcastCampaign::STATUS_DRAFT,
            'split_type' => BroadcastCampaign::SPLIT_SINGLE,
            'split_percentage' => 50,
            'total_recipients' => 0,
            'sent_count' => 0,
            'delivered_count' => 0,
            'read_count' => 0,
            'replied_count' => 0,
            'failed_count' => 0,
        ], $attributes));
    }

    public function test_status_constants_match_service_and_schema_values(): void
    {
        // These literal values are hard-coded in BroadcastCampaignService and
        // the migration default; the constants must never drift from them.
        $this->assertSame('draft', BroadcastCampaign::STATUS_DRAFT);
        $this->assertSame('scheduled', BroadcastCampaign::STATUS_SCHEDULED);
        $this->assertSame('sending', BroadcastCampaign::STATUS_SENDING);
        $this->assertSame('completed', BroadcastCampaign::STATUS_COMPLETED);
        $this->assertSame('cancelled', BroadcastCampaign::STATUS_CANCELLED);

        $this->assertSame(
            ['draft', 'scheduled', 'sending', 'completed', 'cancelled'],
            BroadcastCampaign::STATUSES,
        );
    }

    public function test_split_constants_match_schema_values(): void
    {
        $this->assertSame('single', BroadcastCampaign::SPLIT_SINGLE);
        $this->assertSame('ab_test', BroadcastCampaign::SPLIT_AB_TEST);
        $this->assertSame(['single', 'ab_test'], BroadcastCampaign::SPLIT_TYPES);
    }

    public function test_can_transition_matrix(): void
    {
        $draft = $this->makeCampaign(['status' => BroadcastCampaign::STATUS_DRAFT]);
        $scheduled = $this->makeCampaign(['status' => BroadcastCampaign::STATUS_SCHEDULED]);
        $sending = $this->makeCampaign(['status' => BroadcastCampaign::STATUS_SENDING]);

        $this->assertTrue($draft->canTransitionTo(BroadcastCampaign::STATUS_SCHEDULED));
        $this->assertTrue($draft->canTransitionTo(BroadcastCampaign::STATUS_CANCELLED));
        $this->assertFalse($draft->canTransitionTo(BroadcastCampaign::STATUS_COMPLETED));

        $this->assertTrue($scheduled->canTransitionTo(BroadcastCampaign::STATUS_SENDING));
        $this->assertTrue($scheduled->canTransitionTo(BroadcastCampaign::STATUS_DRAFT)); // back to editing
        $this->assertFalse($scheduled->canTransitionTo(BroadcastCampaign::STATUS_COMPLETED));

        $this->assertTrue($sending->canTransitionTo(BroadcastCampaign::STATUS_COMPLETED));
        $this->assertTrue($sending->canTransitionTo(BroadcastCampaign::STATUS_CANCELLED));
        $this->assertFalse($sending->canTransitionTo(BroadcastCampaign::STATUS_SCHEDULED));
    }

    public function test_terminal_statuses_allow_no_transitions(): void
    {
        $completed = $this->makeCampaign(['status' => BroadcastCampaign::STATUS_COMPLETED]);
        $cancelled = $this->makeCampaign(['status' => BroadcastCampaign::STATUS_CANCELLED]);

        foreach ([['completed' => $completed], ['cancelled' => $cancelled]] as $campaignSet) {
            $campaign = current($campaignSet);
            $ownStatus = key($campaignSet);

            foreach (BroadcastCampaign::STATUSES as $status) {
                if ($status === $ownStatus) {
                    continue; // same-status is idempotent by design
                }

                $this->assertFalse($campaign->canTransitionTo($status), "$ownStatus → $status must be illegal");
            }
        }
    }

    public function test_same_status_transition_is_idempotent(): void
    {
        $campaign = $this->makeCampaign(['status' => BroadcastCampaign::STATUS_DRAFT]);

        $this->assertTrue($campaign->canTransitionTo(BroadcastCampaign::STATUS_DRAFT));
    }

    public function test_transition_to_rejects_illegal_moves_without_persisting(): void
    {
        $completed = $this->makeCampaign(['status' => BroadcastCampaign::STATUS_COMPLETED]);

        $this->assertFalse($completed->transitionTo(BroadcastCampaign::STATUS_DRAFT));
        $this->assertSame(BroadcastCampaign::STATUS_COMPLETED, $completed->status);
    }

    public function test_is_ab_test(): void
    {
        $this->assertFalse($this->makeCampaign(['split_type' => BroadcastCampaign::SPLIT_SINGLE])->isAbTest());
        $this->assertTrue($this->makeCampaign(['split_type' => BroadcastCampaign::SPLIT_AB_TEST])->isAbTest());
    }

    public function test_is_terminal(): void
    {
        $this->assertFalse($this->makeCampaign(['status' => BroadcastCampaign::STATUS_SENDING])->isTerminal());
        $this->assertTrue($this->makeCampaign(['status' => BroadcastCampaign::STATUS_COMPLETED])->isTerminal());
        $this->assertTrue($this->makeCampaign(['status' => BroadcastCampaign::STATUS_CANCELLED])->isTerminal());
    }

    public function test_reply_rate_math(): void
    {
        $this->assertSame(25.0, $this->makeCampaign([
            'sent_count' => 200,
            'replied_count' => 50,
        ])->replyRate());

        $this->assertSame(0.0, $this->makeCampaign([
            'sent_count' => 0,
            'replied_count' => 10, // impossible in practice, but must not divide by zero
        ])->replyRate());
    }

    public function test_failure_rate_math(): void
    {
        $this->assertSame(5.5, $this->makeCampaign([
            'sent_count' => 200,
            'failed_count' => 11,
        ])->failureRate());

        $this->assertSame(0.0, $this->makeCampaign(['sent_count' => 0])->failureRate());
    }

    public function test_targeting_keys_cover_service_query_dimensions(): void
    {
        $expected = [
            'page_id',
            'assigned_agent_id',
            'status',
            'tags',
            'risk_level',
            'opt_in_only',
            'has_ordered',
            'min_order_count',
        ];

        $this->assertSame($expected, BroadcastCampaign::TARGETING_KEYS);
    }
}
