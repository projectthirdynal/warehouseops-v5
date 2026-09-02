<?php

declare(strict_types=1);

use Modules\Shop\Services\WebhookEventKeyGenerator;

it('generates same key for same payload with message mid', function () {
    $payload = [
        'sender' => ['id' => 's1'],
        'recipient' => ['id' => 'r1'],
        'message' => ['mid' => 'mid_abc123', 'text' => 'Hello'],
    ];

    $key1 = WebhookEventKeyGenerator::generate(1, 'messaging', $payload);
    $key2 = WebhookEventKeyGenerator::generate(1, 'messaging', $payload);

    expect($key1)->toBe($key2);
});

it('generates different keys for different mids', function () {
    $payload1 = ['message' => ['mid' => 'mid_1']];
    $payload2 = ['message' => ['mid' => 'mid_2']];

    $key1 = WebhookEventKeyGenerator::generate(1, 'messaging', $payload1);
    $key2 = WebhookEventKeyGenerator::generate(1, 'messaging', $payload2);

    expect($key1)->not->toBe($key2);
});

it('generates same key regardless of key order in payload', function () {
    $payload1 = ['message' => ['mid' => 'mid_x', 'text' => 'hi'], 'sender' => ['id' => 's1']];
    $payload2 = ['sender' => ['id' => 's1'], 'message' => ['text' => 'hi', 'mid' => 'mid_x']];

    $key1 = WebhookEventKeyGenerator::generate(1, 'messaging', $payload1);
    $key2 = WebhookEventKeyGenerator::generate(1, 'messaging', $payload2);

    expect($key1)->toBe($key2);
});

it('generates stable key for delivery watermark events', function () {
    $payload = [
        'sender' => ['id' => 's1'],
        'recipient' => ['id' => 'r1'],
        'delivery' => ['watermark' => 1234567890],
    ];

    $key1 = WebhookEventKeyGenerator::generate(1, 'messaging', $payload);
    $key2 = WebhookEventKeyGenerator::generate(1, 'messaging', $payload);

    expect($key1)->toBe($key2);
});

it('generates different keys for different page ids', function () {
    $payload = ['message' => ['mid' => 'mid_same']];

    $key1 = WebhookEventKeyGenerator::generate(1, 'messaging', $payload);
    $key2 = WebhookEventKeyGenerator::generate(2, 'messaging', $payload);

    expect($key1)->not->toBe($key2);
});
