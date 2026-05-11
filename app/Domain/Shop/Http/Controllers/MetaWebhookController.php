<?php

declare(strict_types=1);

namespace App\Domain\Shop\Http\Controllers;

use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetaWebhookController extends Controller
{
    public function verify(Request $request): Response|JsonResponse
    {
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        if ($mode === 'subscribe' && hash_equals((string) config('services.meta.webhook_verify_token'), (string) $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response()->json(['message' => 'Webhook verification failed.'], 403);
    }

    public function receive(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $rawPayload = $request->getContent();
        $signatureValid = $this->signatureIsValid($rawPayload, (string) $request->header('X-Hub-Signature-256'));

        foreach ($payload['entry'] ?? [] as $entry) {
            $page = FacebookPage::query()->where('page_id', $entry['id'] ?? null)->first();

            foreach (($entry['messaging'] ?? []) as $event) {
                $this->storeEvent($page, $payload['object'] ?? null, 'messaging', $event, $signatureValid);
            }

            foreach (($entry['changes'] ?? []) as $change) {
                $this->storeEvent($page, $payload['object'] ?? null, $change['field'] ?? 'change', $change, $signatureValid);
            }
        }

        return response()->json(['status' => 'received']);
    }

    private function storeEvent(?FacebookPage $page, ?string $object, string $type, array $event, bool $signatureValid): void
    {
        $eventId = $event['message']['mid']
            ?? $event['postback']['mid']
            ?? hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES) ?: serialize($event));

        FacebookWebhookEvent::query()->firstOrCreate(
            ['event_id' => $eventId],
            [
                'facebook_page_id' => $page?->id,
                'object' => $object,
                'event_type' => $type,
                'sender_psid' => data_get($event, 'sender.id'),
                'recipient_id' => data_get($event, 'recipient.id'),
                'payload' => $event,
                'signature_valid' => $signatureValid,
            ]
        );
    }

    private function signatureIsValid(string $payload, string $signatureHeader): bool
    {
        $secret = (string) config('services.meta.app_secret');

        if ($secret === '' || $signatureHeader === '' || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
