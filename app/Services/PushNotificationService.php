<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    /**
     * Subscribe a user to push notifications.
     */
    public function subscribe(User $user, array $subscription): PushSubscription
    {
        return PushSubscription::updateOrCreate(
            [
                'user_id' => $user->id,
                'endpoint' => $subscription['endpoint'],
            ],
            [
                'p256dh_key' => $subscription['keys']['p256dh'] ?? null,
                'auth_key' => $subscription['keys']['auth'] ?? null,
                'content_encoding' => $subscription['contentEncoding'] ?? 'aesgcm',
                'subscribed_at' => now(),
            ]
        );
    }

    /**
     * Unsubscribe a user's push subscription by endpoint.
     */
    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::where('user_id', $user->id)
            ->where('endpoint', $endpoint)
            ->delete();
    }

    /**
     * Send a push notification to a specific user.
     */
    public function sendToUser(int $userId, string $title, string $body, string $url = '/agent/dashboard'): void
    {
        $subscriptions = PushSubscription::where('user_id', $userId)->get();

        foreach ($subscriptions as $sub) {
            $this->send($sub, $title, $body, $url);
        }
    }

    /**
     * Send a push notification to multiple users.
     */
    public function sendToUsers(array $userIds, string $title, string $body, string $url = '/agent/dashboard'): void
    {
        $subscriptions = PushSubscription::whereIn('user_id', $userIds)->get();

        foreach ($subscriptions as $sub) {
            $this->send($sub, $title, $body, $url);
        }
    }

    /**
     * Get subscription stats for a user.
     */
    public function getStats(User $user): array
    {
        $subs = PushSubscription::where('user_id', $user->id)->get();

        return [
            'subscribed' => $subs->count() > 0,
            'subscription_count' => $subs->count(),
            'endpoints' => $subs->map(fn ($s) => [
                'id' => $s->id,
                'endpoint' => $s->endpoint,
                'subscribed_at' => $s->subscribed_at?->toIso8601String(),
            ])->toArray(),
        ];
    }

    /**
     * Send a single push notification via Web Push protocol.
     */
    protected function send(PushSubscription $sub, string $title, string $body, string $url): void
    {
        try {
            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ]);

            // Use VAPID-compatible web push via Guzzle
            // This requires the web-push-php library or manual JWT + encryption
            // For now, we log the notification — actual Web Push requires VAPID keys
            Log::info('PushNotification sent', [
                'user_id' => $sub->user_id,
                'title' => $title,
                'body' => $body,
            ]);

            // If web-push library is installed, use it:
            // $webPush = new WebPush(['VAPID' => [...]]);
            // $webPush->sendNotification($sub->endpoint, $payload, $sub->p256dh_key, $sub->auth_key);
        } catch (\Exception $e) {
            Log::error('PushNotification failed', [
                'subscription_id' => $sub->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
