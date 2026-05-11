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
                    'message_type' => data_get($payload, 'message.attachments') ? 'attachment' : 'text',
                    'body' => $body !== '' ? $body : null,
                    'attachments' => data_get($payload, 'message.attachments'),
                    'phone_candidates' => $detectedPhones,
                    'raw_payload' => $payload,
                    'sent_at' => $this->eventTimestamp($payload),
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
}
