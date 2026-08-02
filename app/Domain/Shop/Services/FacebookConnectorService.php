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
            'pages_manage_engagement',
        ];
    }

    public function requiredWebhookFields(): array
    {
        return [
            'messages',
            'messaging_postbacks',
            'message_deliveries',
            'message_reads',
            'message_reactions',
            'messaging_referrals',
            'feed',
        ];
    }

    public function authorizationUrl(): string
    {
        $state = bin2hex(random_bytes(32));

        session([
            'facebook_oauth_state' => hash('sha256', $state),
            'facebook_oauth_state_expires_at' => now()->addMinutes(10),
        ]);

        $parameters = [
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'state' => $state,
            'scope' => implode(',', $this->requestedScopes()),
            'response_type' => 'code',
        ];

        if (filled(config('services.meta.login_config_id'))) {
            $parameters['config_id'] = config('services.meta.login_config_id');
        }

        $query = http_build_query($parameters);

        return 'https://www.facebook.com/'.config('services.meta.graph_version')."/dialog/oauth?{$query}";
    }

    public function connectFromCallback(User $user, string $code): int
    {
        $baseUrl = 'https://graph.facebook.com/' . config('services.meta.graph_version');
        $tokenResponse = Http::get("{$baseUrl}/oauth/access_token", [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'code' => $code,
        ])->throw()->json();

        $token = $tokenResponse['access_token'] ?? '';
        $tokenExpiresAt = isset($tokenResponse['expires_in'])
            ? now()->addSeconds((int) $tokenResponse['expires_in'])
            : null;
        $dataAccessExpiresAt = isset($tokenResponse['data_access_expires_in'])
            ? now()->addSeconds((int) $tokenResponse['data_access_expires_in'])
            : null;

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
                'token_expires_at' => $tokenExpiresAt,
                'data_access_expires_at' => $dataAccessExpiresAt,
                'status' => 'connected',
                'connection_status' => FacebookAccount::CONNECTION_ACTIVE,
                'connected_at' => now(),
                'last_validated_at' => now(),
                'last_validation_error' => null,
                'reconnect_required_at' => null,
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
                    'connection_status' => 'active',
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
        $baseUrl = 'https://graph.facebook.com/'.config('services.meta.graph_version');

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
        $baseUrl = 'https://graph.facebook.com/'.config('services.meta.graph_version');

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

    public function disconnectPage(FacebookPage $page): void
    {
        $baseUrl = 'https://graph.facebook.com/'.config('services.meta.graph_version');

        try {
            Http::withToken($page->page_access_token)
                ->delete("{$baseUrl}/{$page->page_id}/subscribed_apps")
                ->throw();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Meta-side page unsubscribe failed', [
                'page_id' => $page->page_id,
                'error' => $e->getMessage(),
            ]);
        }

        $page->forceFill([
            'connected_status' => 'disconnected',
            'connection_status' => FacebookAccount::CONNECTION_DISCONNECTED,
            'webhook_status' => 'unsubscribed',
            'page_access_token' => null,
            'token_expires_at' => null,
        ])->save();
    }

    public function revokePermissions(FacebookAccount $account): void
    {
        $baseUrl = 'https://graph.facebook.com/' . config('services.meta.graph_version');

        try {
            Http::withToken($account->access_token)
                ->delete("{$baseUrl}/{$account->facebook_user_id}/permissions")
                ->throw();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Meta-side permission revocation failed', [
                'facebook_user_id' => $account->facebook_user_id,
                'error' => $e->getMessage(),
            ]);
        }

        $account->forceFill([
            'connection_status' => FacebookAccount::CONNECTION_REVOKED,
            'access_token' => null,
            'token_expires_at' => null,
            'data_access_expires_at' => null,
            'reconnect_required_at' => now(),
        ])->save();

        $account->pages()->update([
            'connected_status' => 'disconnected',
            'connection_status' => FacebookAccount::CONNECTION_DISCONNECTED,
            'webhook_status' => 'unsubscribed',
            'page_access_token' => null,
            'token_expires_at' => null,
        ]);
    }

    public function validateToken(FacebookAccount $account): bool
    {
        $baseUrl = 'https://graph.facebook.com/' . config('services.meta.graph_version');

        try {
            $response = Http::get("{$baseUrl}/debug_token", [
                'input_token' => $account->access_token,
                'access_token' => config('services.meta.app_id') . '|' . config('services.meta.app_secret'),
            ])->throw()->json('data');

            $isValid = (bool) ($response['is_valid'] ?? false);

            $account->forceFill([
                'last_validated_at' => now(),
                'last_validation_error' => $isValid ? null : 'Token invalid',
                'connection_status' => $isValid
                    ? FacebookAccount::CONNECTION_ACTIVE
                    : FacebookAccount::CONNECTION_EXPIRED,
            ])->save();

            return $isValid;
        } catch (\Throwable $e) {
            $account->forceFill([
                'last_validated_at' => now(),
                'last_validation_error' => $e->getMessage(),
                'connection_status' => FacebookAccount::CONNECTION_RECONNECT_REQUIRED,
                'reconnect_required_at' => now(),
            ])->save();

            return false;
        }
    }

    public function sendMessage(FacebookPage $page, string $recipientPsid, string $body, ?int $agentId = null): array
    {
        $baseUrl = 'https://graph.facebook.com/' . config('services.meta.graph_version');

        $response = Http::post("{$baseUrl}/me/messages", [
            'access_token' => $page->page_access_token,
            'recipient' => ['id' => $recipientPsid],
            'message' => ['text' => $body],
            'messaging_type' => 'RESPONSE',
        ])->throw()->json();

        return $response;
    }

    /**
     * @param  array<int, array{title: string, payload: string, image_url?: string}>  $quickReplies
     */
    public function sendMessageWithQuickReplies(FacebookPage $page, string $recipientPsid, string $body, array $quickReplies): array
    {
        $baseUrl = 'https://graph.facebook.com/'.config('services.meta.graph_version');

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

    public function sendTypingIndicator(FacebookPage $page, string $recipientPsid): array
    {
        $baseUrl = 'https://graph.facebook.com/'.config('services.meta.graph_version');

        return Http::post("{$baseUrl}/me/messages", [
            'access_token' => $page->page_access_token,
            'recipient' => ['id' => $recipientPsid],
            'sender_action' => 'typing_on',
        ])->throw()->json();
    }

    public function isConfigured(): bool
    {
        return filled(config('services.meta.app_id'))
            && filled(config('services.meta.app_secret'))
            && filled(config('services.meta.redirect_uri'));
    }
}
