<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MetaConversationIngestor
{
    public function __construct(
        private readonly PhoneDetectionService $phones,
        private readonly CustomerIdentityService $customerIdentities,
        private readonly SentimentAnalysisService $sentimentAnalyzer,
    ) {}

    public function process(FacebookWebhookEvent $webhookEvent): void
    {
        if ($webhookEvent->processed_at !== null || $webhookEvent->facebookPage === null) {
            return;
        }

        $payload = $webhookEvent->payload ?? [];
        $senderPsid = data_get($payload, 'sender.id');

        if (! is_string($senderPsid) || $senderPsid === '') {
            $webhookEvent->forceFill([
                'processed_at' => now(),
                'error_message' => 'Missing sender PSID.',
            ])->save();

            return;
        }

        DB::transaction(function () use ($webhookEvent, $payload, $senderPsid) {
            $body = (string) (data_get($payload, 'message.text') ?? data_get($payload, 'postback.title') ?? '');
            $detectedPhones = $this->phones->extract($body);
            $customer = $detectedPhones === [] ? null : $this->customerIdentities->findByPhone($detectedPhones[0]);

            $identity = $this->customerIdentities->upsertFacebookIdentity(
                page: $webhookEvent->facebookPage,
                psid: $senderPsid,
                customer: $customer,
                detectedPhone: $detectedPhones[0] ?? null,
                metadata: ['source' => 'meta_webhook']
            );

            $conversation = Conversation::query()->firstOrNew([
                'thread_key' => "facebook:{$webhookEvent->facebookPage->page_id}:{$senderPsid}",
            ]);

            $conversation->fill([
                'facebook_page_id' => $webhookEvent->facebook_page_id,
                'customer_id' => $customer?->id,
                'customer_identity_id' => $identity->id,
                'channel' => 'messenger',
                'status' => 'open',
                'last_message_preview' => str($body)->limit(160)->toString(),
                'last_message_at' => $this->eventTimestamp($payload),
                'unread_count' => ((int) $conversation->unread_count) + 1,
                'tags' => $detectedPhones === [] ? null : ['phone_detected'],
            ])->save();

            Message::query()->firstOrCreate(
                ['external_message_id' => $webhookEvent->event_id],
                [
                    'conversation_id' => $conversation->id,
                    'facebook_page_id' => $webhookEvent->facebook_page_id,
                    'customer_identity_id' => $identity->id,
                    'direction' => 'inbound',
                    'message_type' => $this->classifyMessageType($payload),
                    'body' => $body !== '' ? $body : null,
                    'attachments' => data_get($payload, 'message.attachments'),
                    'phone_candidates' => $detectedPhones,
                    'raw_payload' => $payload,
                    'sent_at' => $this->eventTimestamp($payload),
                    'read_at' => null,
                    'send_status' => null,
                ]
            );

            // Update sentiment based on recent inbound messages
            if ($body !== '') {
                $recentMessages = $conversation->messages()
                    ->where('direction', 'inbound')
                    ->latest('sent_at')
                    ->limit(10)
                    ->pluck('body')
                    ->filter()
                    ->toArray();

                $sentiment = $this->sentimentAnalyzer->analyze(implode(' ', $recentMessages));
                $conversation->forceFill([
                    'sentiment' => $sentiment['sentiment'],
                    'sentiment_score' => $sentiment['score'],
                ])->save();
            }

            $webhookEvent->forceFill([
                'processed_at' => now(),
                'error_message' => null,
            ])->save();
        });
    }

    /**
     * Process a Page comment (feed change) webhook event into a conversation + message.
     */
    public function processComment(FacebookWebhookEvent $webhookEvent): void
    {
        if ($webhookEvent->processed_at !== null || $webhookEvent->facebookPage === null) {
            return;
        }

        $payload = $webhookEvent->payload ?? [];
        $value = data_get($payload, 'value', []);

        // Only process added comments
        if (data_get($value, 'item') !== 'comment' || data_get($value, 'verb') !== 'add') {
            $webhookEvent->forceFill([
                'processed_at' => now(),
                'error_message' => 'Not an added comment event.',
            ])->save();

            return;
        }

        $commenterId = (string) data_get($value, 'from.id', '');
        $commenterName = (string) data_get($value, 'from.name', '');
        $body = (string) (data_get($value, 'message') ?? '');
        $commentId = (string) data_get($value, 'comment_id', $webhookEvent->event_id);
        $postId = (string) data_get($value, 'post_id', '');

        if ($commenterId === '') {
            $webhookEvent->forceFill([
                'processed_at' => now(),
                'error_message' => 'Missing commenter ID.',
            ])->save();

            return;
        }

        DB::transaction(function () use ($webhookEvent, $value, $commenterId, $commenterName, $body, $commentId, $postId) {
            $detectedPhones = $this->phones->extract($body);
            $customer = $detectedPhones === [] ? null : $this->customerIdentities->findByPhone($detectedPhones[0]);

            $identity = $this->customerIdentities->upsertFacebookIdentity(
                page: $webhookEvent->facebookPage,
                psid: $commenterId,
                customer: $customer,
                displayName: $commenterName !== '' ? $commenterName : null,
                detectedPhone: $detectedPhones[0] ?? null,
                metadata: ['source' => 'page_comment', 'post_id' => $postId]
            );

            $threadKey = "facebook_comment:{$webhookEvent->facebookPage->page_id}:{$commenterId}";

            $conversation = Conversation::query()->firstOrNew([
                'thread_key' => $threadKey,
            ]);

            $conversation->fill([
                'facebook_page_id' => $webhookEvent->facebook_page_id,
                'customer_id' => $customer?->id,
                'customer_identity_id' => $identity->id,
                'channel' => 'comment',
                'status' => 'open',
                'last_message_preview' => str($body)->limit(160)->toString(),
                'last_message_at' => $this->commentTimestamp($value),
                'unread_count' => ((int) $conversation->unread_count) + 1,
                'tags' => $detectedPhones === [] ? null : ['phone_detected'],
            ])->save();

            Message::query()->firstOrCreate(
                ['external_message_id' => $commentId],
                [
                    'conversation_id' => $conversation->id,
                    'facebook_page_id' => $webhookEvent->facebook_page_id,
                    'customer_identity_id' => $identity->id,
                    'direction' => 'inbound',
                    'message_type' => 'text',
                    'body' => $body !== '' ? $body : null,
                    'phone_candidates' => $detectedPhones,
                    'raw_payload' => $payload,
                    'sent_at' => $this->commentTimestamp($value),
                    'read_at' => null,
                    'send_status' => null,
                ]
            );

            $webhookEvent->forceFill([
                'processed_at' => now(),
                'error_message' => null,
            ])->save();
        });
    }

    private function eventTimestamp(array $payload): Carbon
    {
        $timestamp = data_get($payload, 'timestamp');

        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestampMs((int) $timestamp);
        }

        return now();
    }

    private function commentTimestamp(array $value): Carbon
    {
        $timestamp = data_get($value, 'created_time');

        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestamp((int) $timestamp);
        }

        return now();
    }

    /**
     * Classify inbound Messenger message by payload: text, image, voice, file, fallback.
     */
    private function classifyMessageType(array $payload): string
    {
        if (! data_get($payload, 'message.attachments')) {
            return 'text';
        }

        $attachments = data_get($payload, 'message.attachments');
        $firstType = data_get($attachments, '0.type') ?? data_get($attachments, '0.payload.sticker_type');

        return match ($firstType) {
            'image', 'image/jpeg', 'image/png', 'gif' => 'image',
            'audio', 'voice' => 'voice',
            'video' => 'video',
            'file' => 'file',
            default => 'fallback',
        };
    }
}
