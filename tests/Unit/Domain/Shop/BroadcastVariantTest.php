<?php

namespace Tests\Unit\Domain\Shop;

use App\Domain\Shop\Models\BroadcastCampaign;
use App\Domain\Shop\Models\BroadcastVariant;
use App\Domain\Shop\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastVariantTest extends TestCase
{
    use RefreshDatabase;

    private BroadcastCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campaign = BroadcastCampaign::query()->create(['name' => 'Test Campaign']);
    }

    public function test_quick_replies_are_normalized_to_messenger_payload_shape(): void
    {
        $variant = $this->createVariant([
            'quick_replies' => [
                ['title' => 'Yes'],
                ['title' => 'This title is way longer than twenty characters', 'payload' => 'custom_payload'],
                '',
                ['title' => '  '],
                'Plain string reply',
            ],
        ]);

        $this->assertSame([
            [
                'content_type' => 'text',
                'title' => 'Yes',
                'payload' => 'Yes',
            ],
            [
                'content_type' => 'text',
                'title' => 'This title is way lo',
                'payload' => 'custom_payload',
            ],
            [
                'content_type' => 'text',
                'title' => 'Plain string reply',
                'payload' => 'Plain string reply',
            ],
        ], $variant->quick_replies);
        $this->assertTrue($variant->hasQuickReplies());
    }

    public function test_quick_replies_beyond_limit_are_dropped(): void
    {
        $replies = [];

        for ($i = 1; $i <= 15; $i++) {
            $replies[] = ['title' => 'Reply '.$i];
        }

        $variant = $this->createVariant(['quick_replies' => $replies]);

        $this->assertCount(BroadcastVariant::MAX_QUICK_REPLIES, $variant->quick_replies);
        $this->assertSame('Reply 11', $variant->quick_replies[10]['title']);
    }

    public function test_empty_quick_replies_are_stored_as_null(): void
    {
        $variant = $this->createVariant(['quick_replies' => [['title' => '   ']]]);

        $this->assertNull($variant->fresh()->quick_replies);
        $this->assertFalse($variant->hasQuickReplies());
    }

    public function test_stat_counters_are_cast_to_integers(): void
    {
        $variant = $this->createVariant([
            'sent_count' => 40,
            'replied_count' => 10,
            'delivered_count' => 38,
            'read_count' => 30,
        ]);

        $this->assertSame(40, $variant->sent_count);
        $this->assertSame(25.0, $variant->replyRate());
        $this->assertSame(95.0, $variant->deliveryRate());
        $this->assertSame(75.0, $variant->readRate());
    }

    public function test_rates_return_zero_when_nothing_sent(): void
    {
        $variant = $this->createVariant();

        $this->assertSame(0.0, $variant->replyRate());
        $this->assertSame(0.0, $variant->deliveryRate());
        $this->assertSame(0.0, $variant->readRate());
    }

    public function test_record_helpers_increment_statistics(): void
    {
        $variant = $this->createVariant();

        $variant->recordSent();
        $variant->recordSent();
        $variant->recordReplied();
        $variant->recordDelivered();
        $variant->recordRead();
        $variant->recordFailed();

        $variant->refresh();

        $this->assertSame(2, $variant->sent_count);
        $this->assertSame(1, $variant->replied_count);
        $this->assertSame(1, $variant->delivered_count);
        $this->assertSame(1, $variant->read_count);
        $this->assertSame(1, $variant->failed_count);
        $this->assertSame(50.0, $variant->replyRate());
    }

    public function test_determine_winner_returns_highest_reply_rate_with_sends(): void
    {
        $loser = $this->createVariant(['label' => 'A', 'sent_count' => 100, 'replied_count' => 5]);
        $winner = $this->createVariant(['label' => 'B', 'sent_count' => 100, 'replied_count' => 20]);
        $this->createVariant(['label' => 'C', 'sent_count' => 0, 'replied_count' => 99]);

        $winnerDetermined = BroadcastVariant::determineWinner([$loser, $winner]);

        $this->assertNotNull($winnerDetermined);
        $this->assertSame($winner->id, $winnerDetermined->id);
    }

    public function test_determine_winner_returns_null_when_no_variant_was_sent(): void
    {
        $unsent = $this->createVariant(['label' => 'A', 'replied_count' => 10]);

        $this->assertNull(BroadcastVariant::determineWinner([$unsent]));
    }

    public function test_variant_belongs_to_campaign_and_has_recipients_relation(): void
    {
        $variant = $this->createVariant(['label' => 'A']);

        $this->assertTrue($variant->campaign->is($this->campaign));
        $this->assertCount(0, $variant->recipients);
    }

    public function test_recipient_relations_resolve_variant_and_campaign(): void
    {
        $variant = $this->createVariant(['label' => 'A']);
        $conversation = Conversation::query()->create();

        $recipient = $this->campaign->recipients()->create([
            'broadcast_variant_id' => $variant->id,
            'conversation_id' => $conversation->id,
            'status' => 'pending',
        ]);

        $this->assertTrue($recipient->variant->is($variant));
        $this->assertTrue($recipient->campaign->is($this->campaign));
    }

    private function createVariant(array $attributes = []): BroadcastVariant
    {
        return $this->campaign->variants()->create(array_merge([
            'label' => 'A',
            'body' => 'Hello! Check out our new arrivals.',
        ], $attributes));
    }
}
