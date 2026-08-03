<?php

declare(strict_types=1);

use App\Domain\Shop\Jobs\ProcessMetaWebhookEvent;
use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Services\MetaConversationIngestor;
use Illuminate\Support\Facades\Queue;

it('dispatches job for webhook event processing', function () {
    Queue::fake();

    $page = FacebookPage::factory()->create();

    $event = FacebookWebhookEvent::factory()->create([
        'facebook_page_id' => $page->id,
        'event_id' => 'mid_test_dispatch',
        'event_key' => 'key_test_dispatch',
        'signature_valid' => true,
        'status' => FacebookWebhookEvent::STATUS_QUEUED,
        'payload' => [
            'sender' => ['id' => 'sender123'],
            'recipient' => ['id' => $page->page_id],
            'message' => ['mid' => 'mid_test_dispatch', 'text' => 'Hello'],
        ],
    ]);

    ProcessMetaWebhookEvent::dispatch($event->id);

    Queue::assertPushed(ProcessMetaWebhookEvent::class, function ($job) use ($event) {
        return $job->webhookEventId === $event->id;
    });
});

it('marks event as processed on successful handling', function () {
    $page = FacebookPage::factory()->create();

    $event = FacebookWebhookEvent::factory()->create([
        'facebook_page_id' => $page->id,
        'event_id' => 'mid_test_process',
        'event_key' => 'key_test_process',
        'signature_valid' => true,
        'status' => FacebookWebhookEvent::STATUS_QUEUED,
        'payload' => [
            'sender' => ['id' => 'sender123'],
            'recipient' => ['id' => $page->page_id],
            'message' => ['mid' => 'mid_test_process', 'text' => 'Hello world'],
        ],
    ]);

    $ingestor = mock(MetaConversationIngestor::class);
    $ingestor->shouldReceive('process')->once();

    $job = new ProcessMetaWebhookEvent($event->id);
    $job->handle($ingestor);

    $event->refresh();

    expect($event->status)->toBe(FacebookWebhookEvent::STATUS_PROCESSED);
    expect($event->processed_at)->not->toBeNull();
});

it('marks event as failed and increments retry count on exception', function () {
    $page = FacebookPage::factory()->create();

    $event = FacebookWebhookEvent::factory()->create([
        'facebook_page_id' => $page->id,
        'event_id' => 'mid_test_fail',
        'event_key' => 'key_test_fail',
        'signature_valid' => true,
        'status' => FacebookWebhookEvent::STATUS_QUEUED,
        'retry_count' => 0,
        'payload' => [
            'sender' => ['id' => 'sender123'],
            'recipient' => ['id' => $page->page_id],
            'message' => ['mid' => 'mid_test_fail', 'text' => 'Hello'],
        ],
    ]);

    $ingestor = mock(MetaConversationIngestor::class);
    $ingestor->shouldReceive('process')->andThrow(new RuntimeException('Processing error'));

    $job = new ProcessMetaWebhookEvent($event->id);

    try {
        $job->handle($ingestor);
    } catch (RuntimeException $e) {
        // Expected
    }

    $event->refresh();

    expect($event->status)->toBe(FacebookWebhookEvent::STATUS_FAILED);
    expect($event->retry_count)->toBe(1);
    expect($event->last_error)->toBe('Processing error');
});

it('rejects event with invalid signature', function () {
    $page = FacebookPage::factory()->create();

    $event = FacebookWebhookEvent::factory()->create([
        'facebook_page_id' => $page->id,
        'event_id' => 'mid_invalid_sig',
        'event_key' => 'key_invalid_sig',
        'signature_valid' => false,
        'status' => FacebookWebhookEvent::STATUS_QUEUED,
        'payload' => [],
    ]);

    $ingestor = mock(MetaConversationIngestor::class);
    $ingestor->shouldNotReceive('process');

    $job = new ProcessMetaWebhookEvent($event->id);
    $job->handle($ingestor);

    $event->refresh();

    expect($event->status)->toBe(FacebookWebhookEvent::STATUS_REJECTED);
});
