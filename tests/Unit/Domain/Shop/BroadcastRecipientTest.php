<?php

namespace Tests\Unit\Domain\Shop;

use App\Domain\Shop\Models\BroadcastCampaign;
use App\Domain\Shop\Models\BroadcastRecipient;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastRecipientTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_sent_records_message_and_timestamp(): void
    {
        $recipient = $this->makeRecipient();
        $message = Message::query()->create([
            'conversation_id' => $recipient->conversation_id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => 'Hello',
        ]);
        $sentAt = now()->startOfSecond();

        $recipient->markSent($message->id);
        $recipient->refresh();

        $this->assertSame(BroadcastRecipient::STATUS_SENT, $recipient->status);
        $this->assertTrue($recipient->message->is($message));
        $this->assertNull($recipient->error_message);
        $this->assertEquals($sentAt->format('Y-m-d H:i:s'), $recipient->sent_at->format('Y-m-d H:i:s'));
    }

    public function test_mark_failed_logs_error_message(): void
    {
        $recipient = $this->makeRecipient();

        $recipient->markFailed('Graph API returned 400');
        $recipient->refresh();

        $this->assertSame(BroadcastRecipient::STATUS_FAILED, $recipient->status);
        $this->assertSame('Graph API returned 400', $recipient->error_message);
    }

    public function test_mark_skipped_logs_reason(): void
    {
        $recipient = $this->makeRecipient();

        $recipient->markSkipped('Missing page token or PSID');
        $recipient->refresh();

        $this->assertSame(BroadcastRecipient::STATUS_SKIPPED, $recipient->status);
        $this->assertSame('Missing page token or PSID', $recipient->error_message);
    }

    public function test_engagement_marks_transition_status(): void
    {
        $recipient = $this->makeRecipient();

        $recipient->markDelivered();
        $this->assertSame(BroadcastRecipient::STATUS_DELIVERED, $recipient->fresh()->status);

        $recipient->markRead();
        $this->assertSame(BroadcastRecipient::STATUS_READ, $recipient->fresh()->status);

        $recipient->markReplied();
        $this->assertSame(BroadcastRecipient::STATUS_REPLIED, $recipient->fresh()->status);
    }

    public function test_campaign_variant_and_conversation_relations_resolve(): void
    {
        $campaign = BroadcastCampaign::query()->create(['name' => 'Test Campaign']);
        $variant = $campaign->variants()->create(['label' => 'A', 'body' => 'Hello']);
        $conversation = Conversation::query()->create();

        $recipient = $campaign->recipients()->create([
            'broadcast_variant_id' => $variant->id,
            'conversation_id' => $conversation->id,
            'status' => BroadcastRecipient::STATUS_PENDING,
        ]);

        $this->assertTrue($recipient->campaign->is($campaign));
        $this->assertTrue($recipient->variant->is($variant));
        $this->assertTrue($recipient->conversation->is($conversation));
    }

    public function test_new_recipients_default_to_pending_status(): void
    {
        $recipient = $this->makeRecipient()->fresh();

        $this->assertSame(BroadcastRecipient::STATUS_PENDING, $recipient->status);
        $this->assertContains($recipient->status, BroadcastRecipient::STATUSES);
    }

    private function makeRecipient(): BroadcastRecipient
    {
        $campaign = BroadcastCampaign::query()->create(['name' => 'Test Campaign']);
        $variant = $campaign->variants()->create(['label' => 'A', 'body' => 'Hello']);
        $conversation = Conversation::query()->create();

        return BroadcastRecipient::query()->create([
            'broadcast_campaign_id' => $campaign->id,
            'broadcast_variant_id' => $variant->id,
            'conversation_id' => $conversation->id,
        ]);
    }
}
