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
use Throwable;

class ProcessFacebookWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 60, 180, 300];

    public function __construct(private readonly int $webhookEventId)
    {
        $this->onQueue('shop-webhooks');
    }

    public function handle(MetaConversationIngestor $ingestor): void
    {
        $event = FacebookWebhookEvent::query()->find($this->webhookEventId);

        if (! $event || $event->processed_at !== null) {
            return;
        }

        try {
            $ingestor->process($event);
        } catch (Throwable $exception) {
            $event->forceFill([
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        FacebookWebhookEvent::query()
            ->whereKey($this->webhookEventId)
            ->whereNull('processed_at')
            ->update([
                'error_message' => $exception->getMessage(),
                'updated_at' => now(),
            ]);
    }
}
