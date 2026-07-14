<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Models\Message;
use App\Domain\Shop\Models\PageAssignmentRule;
use App\Domain\Shop\Models\Tag;
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

        // Handle sender_action events (typing_on, typing_off, mark_seen)
        $senderAction = data_get($payload, 'sender_action');
        if (is_string($senderAction)) {
            $this->handleSenderAction($webhookEvent, $senderPsid, $senderAction);

            return;
        }

        // Handle reaction events (react/unreact)
        $reactionAction = data_get($payload, 'reaction.action');
        if (is_string($reactionAction)) {
            $this->handleReaction($webhookEvent, $senderPsid, $reactionAction, data_get($payload, 'reaction.emoji'));

            return;
        }

        DB::transaction(function () use ($webhookEvent, $payload, $senderPsid) {
            $body = (string) (data_get($payload, 'message.text') ?? data_get($payload, 'postback.title') ?? '');
            $quickReplyPayload = data_get($payload, 'message.quick_reply.payload');
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

            $isNewConversation = ! $conversation->exists;

            $conversation->fill([
                'facebook_page_id' => $webhookEvent->facebook_page_id,
                'customer_id' => $customer?->id,
                'customer_identity_id' => $identity->id,
                'channel' => 'messenger',
                'status' => 'open',
                'last_message_preview' => str($body)->limit(160)->toString(),
                'last_message_at' => $this->eventTimestamp($payload),
                'unread_count' => ((int) $conversation->unread_count) + 1,
            ])->save();

            if ($isNewConversation && $conversation->assigned_agent_id === null) {
                $this->applyAssignmentRules($conversation);
            }

            if ($detectedPhones !== [] && ! $conversation->tags()->where('slug', 'phone_detected')->exists()) {
                $tag = Tag::firstOrCreate(['slug' => 'phone_detected'], ['name' => 'Phone Detected', 'color' => '#22c55e']);
                $conversation->tags()->syncWithoutDetaching($tag);
            }

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
                    'metadata' => $quickReplyPayload !== null ? ['quick_reply_payload' => $quickReplyPayload] : null,
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

            $isNewConversation = ! $conversation->exists;

            $conversation->fill([
                'facebook_page_id' => $webhookEvent->facebook_page_id,
                'customer_id' => $customer?->id,
                'customer_identity_id' => $identity->id,
                'channel' => 'comment',
                'status' => 'open',
                'last_message_preview' => str($body)->limit(160)->toString(),
                'last_message_at' => $this->commentTimestamp($value),
                'unread_count' => ((int) $conversation->unread_count) + 1,
            ])->save();

            if ($isNewConversation && $conversation->assigned_agent_id === null) {
                $this->applyAssignmentRules($conversation);
            }

            if ($detectedPhones !== [] && ! $conversation->tags()->where('slug', 'phone_detected')->exists()) {
                $tag = Tag::firstOrCreate(['slug' => 'phone_detected'], ['name' => 'Phone Detected', 'color' => '#22c55e']);
                $conversation->tags()->syncWithoutDetaching($tag);
            }

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
                    'moderation_status' => 'pending',
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

    /**
     * Handle sender_action events: typing_on, typing_off, mark_seen.
     */
    private function handleSenderAction(FacebookWebhookEvent $webhookEvent, string $senderPsid, string $action): void
    {
        $conversation = Conversation::query()->where(
            'thread_key',
            "facebook:{$webhookEvent->facebookPage->page_id}:{$senderPsid}"
        )->first();

        if ($conversation) {
            if ($action === 'typing_on') {
                $conversation->forceFill(['typing_at' => now()])->save();
            } elseif ($action === 'typing_off' || $action === 'mark_seen') {
                $conversation->forceFill(['typing_at' => null])->save();
            }
        }

        $webhookEvent->forceFill([
            'processed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    /**
     * Handle reaction events: react (add emoji) or unreact (remove emoji).
     */
    private function handleReaction(
        FacebookWebhookEvent $webhookEvent,
        string $senderPsid,
        string $action,
        ?string $emoji
    ): void {
        $payload = $webhookEvent->payload ?? [];
        $messageMid = data_get($payload, 'reaction.mid') ?? data_get($payload, 'message.mid');

        if (is_string($messageMid)) {
            $message = Message::query()->where('external_message_id', $messageMid)->first();

            if ($message) {
                $reactions = $message->reactions ?? [];

                if ($action === 'react' && $emoji) {
                    $reactions[$senderPsid] = $emoji;
                } elseif ($action === 'unreact') {
                    unset($reactions[$senderPsid]);
                }

                $message->forceFill(['reactions' => $reactions])->save();
            }
        }

        $webhookEvent->forceFill([
            'processed_at' => now(),
            'error_message' => null,
        ])->save();
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
        if (data_get($payload, 'message.quick_reply.payload') !== null) {
            return 'quick_reply';
        }

        if (data_get($payload, 'postback.payload') !== null) {
            return 'postback';
        }

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

    private function applyAssignmentRules(Conversation $conversation): void
    {
        $rule = PageAssignmentRule::query()
            ->where('facebook_page_id', $conversation->facebook_page_id)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if ($rule !== null) {
            $conversation->forceFill([
                'assigned_agent_id' => $rule->user_id,
            ])->save();
        }
    }
}
