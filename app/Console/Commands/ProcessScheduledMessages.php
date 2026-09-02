<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Modules\Shop\Models\Message;
use Modules\Shop\Models\ScheduledMessage;
use Modules\Shop\Services\FacebookConnectorService;
use Illuminate\Console\Command;

class ProcessScheduledMessages extends Command
{
    protected $signature = 'shop:process-scheduled-messages';

    protected $description = 'Send scheduled messages that are due';

    public function handle(FacebookConnectorService $facebookConnector): int
    {
        $due = ScheduledMessage::query()
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->with(['conversation.facebookPage', 'conversation.identity'])
            ->limit(50)
            ->get();

        if ($due->isEmpty()) {
            $this->info('No scheduled messages due.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($due as $scheduled) {
            $conversation = $scheduled->conversation;

            if (! $conversation->facebookPage?->page_access_token || ! $conversation->identity?->provider_user_id) {
                $scheduled->forceFill([
                    'status' => 'failed',
                    'error_message' => 'Missing Facebook page access token or customer PSID',
                ])->save();
                $failed++;

                continue;
            }

            try {
                $quickReplies = $scheduled->quick_replies ?? [];

                if ($quickReplies !== []) {
                    $delivery = $facebookConnector->sendMessageWithQuickReplies(
                        $conversation->facebookPage,
                        $conversation->identity->provider_user_id,
                        $scheduled->body,
                        $quickReplies
                    );
                } else {
                    $delivery = $facebookConnector->sendMessage(
                        $conversation->facebookPage,
                        $conversation->identity->provider_user_id,
                        $scheduled->body
                    );
                }

                $message = Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'facebook_page_id' => $conversation->facebook_page_id,
                    'customer_identity_id' => $conversation->customer_identity_id,
                    'external_message_id' => $delivery['message_id'] ?? ('local-'.str()->uuid()),
                    'direction' => 'outbound',
                    'message_type' => $quickReplies !== [] ? 'quick_reply' : 'text',
                    'body' => $scheduled->body,
                    'metadata' => $quickReplies !== [] ? ['quick_replies' => $quickReplies] : null,
                    'raw_payload' => $delivery,
                    'sent_at' => now(),
                    'send_status' => 'sent',
                    'retry_count' => 0,
                ]);

                $conversation->forceFill([
                    'last_message_preview' => $scheduled->body,
                    'last_message_at' => now(),
                    'draft_body' => null,
                ])->save();

                $scheduled->forceFill([
                    'status' => 'sent',
                    'sent_message_id' => $message->id,
                ])->save();

                $sent++;
            } catch (\Throwable $e) {
                $scheduled->forceFill([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ])->save();
                $failed++;
            }
        }

        $this->info("Processed {$due->count()} scheduled messages: {$sent} sent, {$failed} failed.");

        return self::SUCCESS;
    }
}
