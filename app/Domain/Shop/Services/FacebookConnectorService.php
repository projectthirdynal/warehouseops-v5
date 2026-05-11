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
    public function authorizationUrl(): string
    {
        $query = http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'state' => csrf_token(),
            'scope' => implode(',', [
                'pages_show_list',
                'pages_manage_metadata',
                'pages_messaging',
                'pages_read_engagement',
            ]),
            'response_type' => 'code',
        ]);

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
            'subscribed_fields' => implode(',', ['messages', 'messaging_postbacks', 'feed']),
        ])->throw();

        $metadata = $page->metadata ?? [];
        $metadata['subscribed_fields'] = ['messages', 'messaging_postbacks', 'feed'];
        $metadata['subscribed_at'] = now()->toIso8601String();

        $page->forceFill([
            'webhook_status' => 'subscribed',
            'metadata' => $metadata,
        ])->save();
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

    public function isConfigured(): bool
    {
        return filled(config('services.meta.app_id'))
            && filled(config('services.meta.app_secret'))
            && filled(config('services.meta.redirect_uri'));
    }
}
