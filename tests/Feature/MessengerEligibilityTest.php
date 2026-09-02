<?php

declare(strict_types=1);

use Modules\Shop\Models\Conversation;
use Modules\Shop\Services\MessengerEligibilityService;
use Carbon\Carbon;

it('allows response within 24 hour window', function () {
    $service = app(MessengerEligibilityService::class);

    $conversation = Conversation::factory()->create([
        'last_customer_message_at' => now()->subHours(2),
        'response_window_expires_at' => now()->addHours(22),
    ]);

    expect($service->canSendResponse($conversation))->toBeTrue();
});

it('blocks response after 24 hour window', function () {
    $service = app(MessengerEligibilityService::class);

    $conversation = Conversation::factory()->create([
        'last_customer_message_at' => now()->subHours(25),
        'response_window_expires_at' => now()->subHours(1),
    ]);

    expect($service->canSendResponse($conversation))->toBeFalse();
});

it('blocks response when no customer message exists', function () {
    $service = app(MessengerEligibilityService::class);

    $conversation = Conversation::factory()->create([
        'last_customer_message_at' => null,
        'last_message_at' => null,
        'response_window_expires_at' => null,
    ]);

    expect($service->canSendResponse($conversation))->toBeFalse();
});

it('returns expiration time 24 hours after last customer message', function () {
    $service = app(MessengerEligibilityService::class);

    $messageAt = Carbon::now()->subHours(5);
    $conversation = Conversation::factory()->create([
        'last_customer_message_at' => $messageAt,
    ]);

    $expiresAt = $service->standardWindowExpiresAt($conversation);

    expect($expiresAt)->not->toBeNull();
    expect($expiresAt->toDateTimeString())->toBe($messageAt->copy()->addHours(24)->toDateTimeString());
});
