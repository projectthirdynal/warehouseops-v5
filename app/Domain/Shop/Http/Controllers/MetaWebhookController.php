<?php

declare(strict_types=1);

namespace App\Domain\Shop\Http\Controllers;

use App\Domain\Shop\Jobs\ProcessMetaWebhookEvent;
use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Services\WebhookEventKeyGenerator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $requestId = (string) Str::uuid();
        $rawPayload = $request->getContent();
        $signatureValid = $this->signatureIsValid($rawPayload, (string) $request->header('X-Hub-Signature-256'));

        if (!$signatureValid) {
            Log::warning('Rejected invalid Meta webhook signature', [
                'request_id' => $requestId,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Invalid webhook signature',
            ], 403);
        }

        $payload = $request->json()->all();
        $object = $payload['object'] ?? null;

        $storedCount = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            $page = FacebookPage::query()->where('page_id', $entry['id'] ?? null)->first();

            foreach (($entry['messaging'] ?? []) as $event) {
                $this->storeAndDispatch($page, $object, 'messaging', $event, true, $requestId);
                $storedCount++;
            }

            foreach (($entry['changes'] ?? []) as $change) {
                $this->storeAndDispatch($page, $object, $change['field'] ?? 'change', $change, true, $requestId);
                $storedCount++;
            }
        }

        return response()->json([
            'status' => 'received',
            'events' => $storedCount,
            'request_id' => $requestId,
        ]);
    }

    private function storeAndDispatch(
        ?FacebookPage $page,
        ?string $object,
        string $type,
        array $event,
        bool $signatureValid,
        string $requestId,
    ): void {
        $eventKey = WebhookEventKeyGenerator::generate(
            $page?->id,
            $type,
            $event,
        );

        $existing = FacebookWebhookEvent::query()
            ->where('event_key', $eventKey)
            ->where('status', '!=', FacebookWebhookEvent::STATUS_REJECTED)
            ->first();

        if ($existing) {
            Log::info('Meta webhook duplicate event skipped', [
                'event_key' => $eventKey,
                'request_id' => $requestId,
            ]);
            return;
        }

        $webhookEvent = FacebookWebhookEvent::create([
            'facebook_page_id' => $page?->id,
            'event_id' => data_get($event, 'message.mid')
                ?? data_get($event, 'postback.mid')
                ?? data_get($event, 'value.comment_id')
                ?? $eventKey,
            'event_key' => $eventKey,
            'object' => $object,
            'event_type' => $type,
            'sender_psid' => data_get($event, 'sender.id') ?? data_get($event, 'value.from.id'),
            'recipient_id' => data_get($event, 'recipient.id'),
            'payload' => $event,
            'signature_valid' => $signatureValid,
            'status' => FacebookWebhookEvent::STATUS_QUEUED,
        ]);

        if ($page) {
            $page->forceFill(['last_webhook_at' => now()])->save();
        }

        ProcessMetaWebhookEvent::dispatch($webhookEvent->id);

        Log::info('Meta webhook event queued', [
            'event_id' => $webhookEvent->id,
            'event_key' => $eventKey,
            'type' => $type,
            'request_id' => $requestId,
        ]);
    }

    private function signatureIsValid(string $payload, string $signatureHeader): bool
    {
        $secret = (string) config('services.meta.app_secret');

        if ($secret === '' || $signatureHeader === '' || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
