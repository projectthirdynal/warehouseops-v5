<?php

declare(strict_types=1);

namespace App\Domain\Shop\Jobs;

use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Services\MetaConversationIngestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMetaWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 5;

    public int $backoff = 10;

    public function __construct(
        public int $webhookEventId,
    ) {}

    public function handle(MetaConversationIngestor $ingestor): void
    {
        $event = FacebookWebhookEvent::find($this->webhookEventId);

        if (! $event) {
            Log::warning('ProcessMetaWebhookEvent: event not found', [
                'event_id' => $this->webhookEventId,
            ]);

            return;
        }

        if ($event->status === FacebookWebhookEvent::STATUS_PROCESSED) {
            Log::info('ProcessMetaWebhookEvent: already processed, skipping', [
                'event_id' => $this->webhookEventId,
                'event_key' => $event->event_key,
            ]);

            return;
        }

        if (! $event->signature_valid) {
            $event->markRejected('Invalid signature');
            Log::warning('ProcessMetaWebhookEvent: rejected invalid signature', [
                'event_id' => $this->webhookEventId,
            ]);

            return;
        }

        $event->markProcessing();

        try {
            $ingestor->process($event);
            $event->markProcessed();
        } catch (Throwable $e) {
            $event->markFailed($e->getMessage());

            Log::error('ProcessMetaWebhookEvent: processing failed', [
                'event_id' => $this->webhookEventId,
                'event_key' => $event->event_key,
                'error' => $e->getMessage(),
                'retry_count' => $event->retry_count,
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $event = FacebookWebhookEvent::find($this->webhookEventId);

        if ($event) {
            if ($this->attempts() >= $this->tries) {
                $event->markDeadLetter($exception->getMessage());
                Log::critical('ProcessMetaWebhookEvent: moved to dead-letter', [
                    'event_id' => $this->webhookEventId,
                    'error' => $exception->getMessage(),
                ]);
            } else {
                $event->markFailed($exception->getMessage());
            }
        }
    }
}
