<?php

declare(strict_types=1);

use App\Domain\Shop\Http\Controllers\MetaWebhookController;
use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Http\Request;

beforeEach(function () {
    Config::set('services.meta.app_secret', 'test-secret');
    Config::set('services.meta.webhook_verify_token', 'test-verify-token');

    $this->page = FacebookPage::factory()->create([
        'page_id' => '123456789',
        'page_name' => 'Test Page',
    ]);
});

it('rejects webhook with invalid signature', function () {
    $payload = json_encode([
        'object' => 'page',
        'entry' => [['id' => '123456789', 'messaging' => []]],
    ]);

    $request = Request::create('/api/webhooks/meta', 'POST', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid-signature',
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $controller = app(MetaWebhookController::class);
    $response = $controller->receive($request);

    expect($response->getStatusCode())->toBe(403);
});

it('rejects webhook with missing signature header', function () {
    $payload = json_encode([
        'object' => 'page',
        'entry' => [['id' => '123456789', 'messaging' => []]],
    ]);

    $request = Request::create('/api/webhooks/meta', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $controller = app(MetaWebhookController::class);
    $response = $controller->receive($request);

    expect($response->getStatusCode())->toBe(403);
});

it('accepts webhook with valid signature and queues events', function () {
    Queue::fake();

    $payload = json_encode([
        'object' => 'page',
        'entry' => [[
            'id' => '123456789',
            'messaging' => [[
                'sender' => ['id' => 'sender123'],
                'recipient' => ['id' => '123456789'],
                'message' => ['mid' => 'mid_test_123', 'text' => 'Hello'],
            ]],
        ]],
    ]);

    $signature = 'sha256=' . hash_hmac('sha256', $payload, 'test-secret');

    $request = Request::create('/api/webhooks/meta', 'POST', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $controller = app(MetaWebhookController::class);
    $response = $controller->receive($request);

    expect($response->getStatusCode())->toBe(200);

    $event = FacebookWebhookEvent::query()->where('event_id', 'mid_test_123')->first();
    expect($event)->not->toBeNull();
    expect($event->signature_valid)->toBeTrue();
    expect($event->status)->toBe(FacebookWebhookEvent::STATUS_QUEUED);
});

it('skips duplicate webhook events by event key', function () {
    Queue::fake();

    $eventData = [
        'sender' => ['id' => 'sender123'],
        'recipient' => ['id' => '123456789'],
        'message' => ['mid' => 'mid_dup_test', 'text' => 'Hello'],
    ];

    $payload = json_encode([
        'object' => 'page',
        'entry' => [['id' => '123456789', 'messaging' => [$eventData]]],
    ]);

    $signature = 'sha256=' . hash_hmac('sha256', $payload, 'test-secret');

    $request = Request::create('/api/webhooks/meta', 'POST', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $controller = app(MetaWebhookController::class);

    $controller->receive($request);
    $controller->receive($request);

    expect(FacebookWebhookEvent::query()->where('event_id', 'mid_dup_test')->count())->toBe(1);
});

it('verifies webhook subscription with correct token', function () {
    $request = Request::create('/api/webhooks/meta', 'GET', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'test-verify-token',
        'hub_challenge' => 'challenge123',
    ]);

    $controller = app(MetaWebhookController::class);
    $response = $controller->verify($request);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('challenge123');
});

it('fails webhook verification with wrong token', function () {
    $request = Request::create('/api/webhooks/meta', 'GET', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'wrong-token',
        'hub_challenge' => 'challenge123',
    ]);

    $controller = app(MetaWebhookController::class);
    $response = $controller->verify($request);

    expect($response->getStatusCode())->toBe(403);
});
