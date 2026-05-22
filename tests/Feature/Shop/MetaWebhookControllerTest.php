<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\FacebookPage;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_signed_messages_for_each_connected_page_once(): void
    {
        config([
            'services.meta.app_secret' => 'test-secret',
            'services.meta.graph_version' => 'v21.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v21.0/psid-page-a*' => Http::response(['id' => 'psid-page-a', 'name' => 'Maria Page A']),
            'https://graph.facebook.com/v21.0/psid-page-b*' => Http::response(['id' => 'psid-page-b', 'name' => 'Maria Page B']),
        ]);

        $pageA = FacebookPage::query()->create([
            'page_id' => 'page-a',
            'page_name' => 'Shop Page A',
            'page_access_token' => 'token-a',
            'connected_status' => 'connected',
            'webhook_status' => 'subscribed',
        ]);
        $pageB = FacebookPage::query()->create([
            'page_id' => 'page-b',
            'page_name' => 'Shop Page B',
            'page_access_token' => 'token-b',
            'connected_status' => 'connected',
            'webhook_status' => 'subscribed',
        ]);

        $this->postSignedMetaPayload([
            'object' => 'page',
            'entry' => [
                [
                    'id' => $pageA->page_id,
                    'messaging' => [
                        $this->messageEvent($pageA->page_id, 'psid-page-a', 'mid-shared', '09171234567 from page A'),
                    ],
                ],
                [
                    'id' => $pageB->page_id,
                    'messaging' => [
                        $this->messageEvent($pageB->page_id, 'psid-page-b', 'mid-shared', '09181234567 from page B'),
                    ],
                ],
            ],
        ])->assertOk();

        $this->postSignedMetaPayload([
            'object' => 'page',
            'entry' => [
                [
                    'id' => $pageA->page_id,
                    'messaging' => [
                        $this->messageEvent($pageA->page_id, 'psid-page-a', 'mid-shared', '09171234567 duplicate from page A'),
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(2, FacebookWebhookEvent::query()->count());
        $this->assertSame(2, Conversation::query()->count());
        $this->assertSame(2, Message::query()->count());

        $this->assertDatabaseHas('conversations', [
            'facebook_page_id' => $pageA->id,
            'thread_key' => 'facebook:page-a:psid-page-a',
        ]);
        $this->assertDatabaseHas('conversations', [
            'facebook_page_id' => $pageB->id,
            'thread_key' => 'facebook:page-b:psid-page-b',
        ]);
    }

    public function test_it_records_but_does_not_process_invalid_signatures_when_app_secret_is_configured(): void
    {
        config([
            'services.meta.app_secret' => 'test-secret',
            'services.meta.graph_version' => 'v21.0',
        ]);

        $page = FacebookPage::query()->create([
            'page_id' => 'page-signed',
            'page_name' => 'Signed Page',
            'page_access_token' => 'token',
            'connected_status' => 'connected',
            'webhook_status' => 'subscribed',
        ]);

        $payload = [
            'object' => 'page',
            'entry' => [
                [
                    'id' => $page->page_id,
                    'messaging' => [
                        $this->messageEvent($page->page_id, 'psid-invalid', 'mid-invalid', '09191234567 invalid signature'),
                    ],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/meta', $payload)->assertOk();

        $event = FacebookWebhookEvent::query()->firstOrFail();

        $this->assertFalse($event->signature_valid);
        $this->assertSame('Meta webhook signature is invalid.', $event->error_message);
        $this->assertNull($event->processed_at);
        $this->assertSame(0, Conversation::query()->count());
        $this->assertSame(0, Message::query()->count());
    }

    public function test_it_records_unknown_page_payloads_without_processing_them(): void
    {
        config(['services.meta.app_secret' => 'test-secret']);

        $this->postSignedMetaPayload([
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'missing-page',
                    'messaging' => [
                        $this->messageEvent('missing-page', 'psid-unknown', 'mid-unknown', '09161234567 unknown page'),
                    ],
                ],
            ],
        ])->assertOk();

        $event = FacebookWebhookEvent::query()->firstOrFail();

        $this->assertNull($event->facebook_page_id);
        $this->assertTrue($event->signature_valid);
        $this->assertSame('Facebook Page is not connected in WarehouseOps.', $event->error_message);
        $this->assertNull($event->processed_at);
        $this->assertSame(0, Conversation::query()->count());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postSignedMetaPayload(array $payload)
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', (string) $json, (string) config('services.meta.app_secret'));

        return $this->call(
            'POST',
            '/api/webhooks/meta',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            (string) $json
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function messageEvent(string $pageId, string $senderPsid, string $messageId, string $text): array
    {
        return [
            'sender' => ['id' => $senderPsid],
            'recipient' => ['id' => $pageId],
            'timestamp' => now()->getTimestampMs(),
            'message' => [
                'mid' => $messageId,
                'text' => $text,
            ],
        ];
    }
}
