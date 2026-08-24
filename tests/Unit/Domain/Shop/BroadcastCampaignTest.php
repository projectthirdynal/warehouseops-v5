<?php

namespace Tests\Unit\Domain\Shop;

use App\Domain\Shop\Models\BroadcastCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BroadcastCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_constants_cover_all_statuses(): void
    {
        $this->assertSame([
            'draft',
            'scheduled',
            'sending',
            'completed',
            'cancelled',
        ], BroadcastCampaign::STATUSES);
    }

    public function test_only_draft_and_scheduled_campaigns_can_be_sent(): void
    {
        $this->assertTrue($this->makeCampaign(['status' => BroadcastCampaign::STATUS_DRAFT])->canBeSent());
        $this->assertTrue($this->makeCampaign(['status' => BroadcastCampaign::STATUS_SCHEDULED])->canBeSent());
        $this->assertFalse($this->makeCampaign(['status' => BroadcastCampaign::STATUS_SENDING])->canBeSent());
        $this->assertFalse($this->makeCampaign(['status' => BroadcastCampaign::STATUS_COMPLETED])->canBeSent());
        $this->assertFalse($this->makeCampaign(['status' => BroadcastCampaign::STATUS_CANCELLED])->canBeSent());
    }

    public function test_completed_and_cancelled_campaigns_cannot_be_cancelled(): void
    {
        $this->assertTrue($this->makeCampaign(['status' => BroadcastCampaign::STATUS_DRAFT])->canBeCancelled());
        $this->assertTrue($this->makeCampaign(['status' => BroadcastCampaign::STATUS_SCHEDULED])->canBeCancelled());
        $this->assertTrue($this->makeCampaign(['status' => BroadcastCampaign::STATUS_SENDING])->canBeCancelled());
        $this->assertFalse($this->makeCampaign(['status' => BroadcastCampaign::STATUS_COMPLETED])->canBeCancelled());
        $this->assertFalse($this->makeCampaign(['status' => BroadcastCampaign::STATUS_CANCELLED])->canBeCancelled());
    }

    public function test_reply_rate_is_computed_from_sent_count(): void
    {
        $campaign = $this->makeCampaign([
            'sent_count' => 40,
            'replied_count' => 10,
        ]);

        $this->assertSame(25.0, $campaign->replyRate());

        $unsent = $this->makeCampaign();

        $this->assertSame(0.0, $unsent->replyRate());
    }

    public function test_is_ab_test_detects_split_type(): void
    {
        $single = $this->makeCampaign(['split_type' => BroadcastCampaign::SPLIT_SINGLE]);
        $abTest = $this->makeCampaign(['split_type' => BroadcastCampaign::SPLIT_AB_TEST]);

        $this->assertFalse($single->isAbTest());
        $this->assertTrue($abTest->isAbTest());
    }

    public function test_scheduled_and_due_scope_returns_only_due_scheduled_campaigns(): void
    {
        Carbon::setTestNow('2026-08-24 08:00:00');

        $due = $this->makeCampaign([
            'status' => BroadcastCampaign::STATUS_SCHEDULED,
            'scheduled_at' => now()->subMinutes(5),
        ]);
        $future = $this->makeCampaign([
            'status' => BroadcastCampaign::STATUS_SCHEDULED,
            'scheduled_at' => now()->addHour(),
        ]);
        $this->makeCampaign(['status' => BroadcastCampaign::STATUS_DRAFT]);

        $dueCampaigns = BroadcastCampaign::query()->scheduledAndDue()->get();

        $this->assertCount(1, $dueCampaigns);
        $this->assertTrue($dueCampaigns->first()->is($due));
        $this->assertFalse($dueCampaigns->contains($future));
    }

    public function test_winning_variant_delegates_to_highest_reply_rate(): void
    {
        $campaign = BroadcastCampaign::query()->create(['name' => 'A/B Campaign']);
        $loser = $campaign->variants()->create(['label' => 'A', 'body' => 'A body', 'sent_count' => 100, 'replied_count' => 5]);
        $winner = $campaign->variants()->create(['label' => 'B', 'body' => 'B body', 'sent_count' => 100, 'replied_count' => 20]);

        $winning = $campaign->winningVariant();

        $this->assertNotNull($winning);
        $this->assertSame($winner->id, $winning->id);
        $this->assertNotSame($loser->id, $winning->id);
    }

    public function test_counters_are_cast_to_integers(): void
    {
        $campaign = $this->makeCampaign([
            'total_recipients' => 50,
            'sent_count' => 45,
            'failed_count' => 5,
        ]);

        $this->assertSame(50, $campaign->total_recipients);
        $this->assertSame(45, $campaign->sent_count);
        $this->assertSame(5, $campaign->failed_count);
    }

    private function makeCampaign(array $attributes = []): BroadcastCampaign
    {
        return BroadcastCampaign::query()->create(array_merge([
            'name' => 'Test Campaign',
        ], $attributes));
    }
}
