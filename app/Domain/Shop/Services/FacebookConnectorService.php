<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\FacebookAccount;
use App\Domain\Shop\Models\FacebookPage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FacebookConnectorService
{
    public function requestedScopes(): array
    {
        return [
            'pages_show_list',
            'pages_manage_metadata',
            'pages_messaging',
        ];
    }

    public function requiredWebhookFields(): array
    {
        return [
            'messages',
            'messaging_postbacks',
            'feed',
        ];
    }

    public function authorizationUrl(): string
    {
        $parameters = [
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'state' => csrf_token(),
            'scope' => implode(',', $this->requestedScopes()),
            'response_type' => 'code',
        ];

        if (filled(config('services.meta.login_config_id'))) {
            $parameters['config_id'] = config('services.meta.login_config_id');
        }

        $query = http_build_query($parameters);

        return "https://www.facebook.com/" . config('services.meta.graph_version') . "/dialog/oauth?{$query}";
    }

    public function connectFromCallback(User $user, string $code): int
    {
        $baseUrl = 'https://graph.facebook.com/' . config('services.meta.graph_version');
        $token = Http::get("{$baseUrl}/oauth/access_token", [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'code' => $code,
        ])->throw()->json('access_token');

        $profile = Http::get("{$baseUrl}/me", [
            'fields' => 'id,name,email',
            'access_token' => $token,
        ])->throw()->json();

        $account = FacebookAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'facebook_user_id' => $profile['id'],
            ],
            [
                'facebook_user_name' => $profile['name'] ?? null,
                'email' => $profile['email'] ?? null,
                'access_token' => $token,
                'status' => 'connected',
                'connected_at' => now(),
                'metadata' => ['connected_via' => 'shop_oauth'],
            ]
        );

        $pages = Http::get("{$baseUrl}/me/accounts", [
            'fields' => 'id,name,category,access_token,tasks',
            'access_token' => $token,
        ])->throw()->json('data') ?? [];

        foreach ($pages as $page) {
            FacebookPage::query()->updateOrCreate(
                ['page_id' => $page['id']],
                [
                    'facebook_account_id' => $account->id,
                    'connected_by' => $user->id,
                    'page_name' => $page['name'],
                    'category' => $page['category'] ?? null,
                    'page_access_token' => $page['access_token'] ?? null,
                    'connected_status' => 'connected',
                    'webhook_status' => 'pending',
                    'last_sync_at' => now(),
                    'metadata' => [
                        'tasks' => $page['tasks'] ?? [],
                        'sync_id' => (string) Str::uuid(),
                    ],
                ]
            );
        }

        return count($pages);
    }

    public function subscribePage(FacebookPage $page): void
    {
        $baseUrl = 'https://graph.facebook.com/' . config('services.meta.graph_version');

        Http::post("{$baseUrl}/{$page->page_id}/subscribed_apps", [
            'access_token' => $page->page_access_token,
            'subscribed_fields' => implode(',', $this->requiredWebhookFields()),
        ])->throw();

        $metadata = $page->metadata ?? [];
        $metadata['subscribed_fields'] = $this->requiredWebhookFields();
        $metadata['subscribed_at'] = now()->toIso8601String();

        $page->forceFill([
            'webhook_status' => 'subscribed',
            'metadata' => $metadata,
        ])->save();
    }

    public function checkPageSubscription(FacebookPage $page): array
    {
        $baseUrl = 'https://graph.facebook.com/' . config('services.meta.graph_version');

        $apps = Http::get("{$baseUrl}/{$page->page_id}/subscribed_apps", [
            'access_token' => $page->page_access_token,
        ])->throw()->json('data') ?? [];

        $appId = (string) config('services.meta.app_id');
        $subscription = collect($apps)->first(fn (array $app) => (string) ($app['id'] ?? '') === $appId);
        $subscribedFields = is_array($subscription) ? ($subscription['subscribed_fields'] ?? []) : [];
        $fields = collect($subscribedFields)
            ->map(fn ($field) => is_array($field) ? ($field['name'] ?? null) : $field)
            ->filter()
            ->values()
            ->all();
        $requiredFields = $this->requiredWebhookFields();
        $missingFields = array_values(array_diff($requiredFields, $fields));

        $metadata = $page->metadata ?? [];
        $metadata['subscription_checked_at'] = now()->toIso8601String();
        $metadata['subscription_fields'] = $fields;
        $metadata['subscription_missing_fields'] = $missingFields;

        $status = $subscription && empty($missingFields) ? 'subscribed' : 'needs_retry';

        $page->forceFill([
            'webhook_status' => $status,
            'metadata' => $metadata,
        ])->save();

        return [
            'status' => $status,
            'fields' => $fields,
            'missing_fields' => $missingFields,
        ];
    }

    public function sendMessage(FacebookPage $page, string $recipientPsid, string $body): array
    {
        $baseUrl = 'https://graph.facebook.com/' . config('services.meta.graph_version');

        return Http::post("{$baseUrl}/me/messages", [
            'access_token' => $page->page_access_token,
            'recipient' => ['id' => $recipientPsid],
            'message' => ['text' => $body],
            'messaging_type' => 'RESPONSE',
        ])->throw()->json();
    }

    /**
     * @param array<int, array{title: string, payload: string, image_url?: string}> $quickReplies
     */
    public function sendMessageWithQuickReplies(FacebookPage $page, string $recipientPsid, string $body, array $quickReplies): array
    {
        $baseUrl = 'https://graph.facebook.com/' . config('services.meta.graph_version');

        $message = [
            'text' => $body,
            'quick_replies' => array_map(fn ($reply) => array_filter([
                'content_type' => 'text',
                'title' => mb_substr($reply['title'], 0, 20),
                'payload' => $reply['payload'],
                'image_url' => $reply['image_url'] ?? null,
            ]), $quickReplies),
        ];

        return Http::post("{$baseUrl}/me/messages", [
            'access_token' => $page->page_access_token,
            'recipient' => ['id' => $recipientPsid],
            'message' => $message,
            'messaging_type' => 'RESPONSE',
        ])->throw()->json();
    }

    public function isConfigured(): bool
    {
        return filled(config('services.meta.app_id'))
            && filled(config('services.meta.app_secret'))
            && filled(config('services.meta.redirect_uri'));
    }
}
