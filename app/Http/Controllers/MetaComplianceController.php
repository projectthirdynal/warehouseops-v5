<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\CustomerIdentity;
use App\Domain\Shop\Models\FacebookAccount;
use App\Domain\Shop\Models\FacebookWebhookEvent;
use App\Domain\Shop\Models\Message;
use App\Domain\Shop\Models\MetaDataDeletionRequest;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MetaComplianceController extends Controller
{
    public function privacy(): View
    {
        return view('meta.privacy', $this->complianceViewData());
    }

    public function terms(): View
    {
        return view('meta.terms', $this->complianceViewData());
    }

    public function dataDeletionInfo(): View
    {
        return view('meta.data-deletion', array_merge($this->complianceViewData(), [
            'callbackUrl' => route('meta.data-deletion.handle'),
        ]));
    }

    public function handleDataDeletion(Request $request): JsonResponse
    {
        $signedRequest = (string) $request->input('signed_request', '');

        if ($signedRequest === '') {
            return response()->json(['message' => 'signed_request is required.'], 400);
        }

        $payload = $this->parseSignedRequest($signedRequest);

        if ($payload === null) {
            return response()->json(['message' => 'Invalid signed_request.'], 400);
        }

        $deletionRequest = MetaDataDeletionRequest::query()->create([
            'confirmation_code' => (string) Str::uuid(),
            'app_scoped_user_id' => $payload['user_id'] ?? null,
            'status' => 'processing',
            'source' => 'meta_callback',
            'payload' => $payload,
            'requested_at' => now(),
        ]);

        $summary = $this->deleteMetaLinkedData((string) ($payload['user_id'] ?? ''));

        $deletionRequest->update([
            'status' => 'completed',
            'result_summary' => $summary,
            'processed_at' => now(),
            'completed_at' => now(),
        ]);

        return response()->json([
            'url' => route('meta.data-deletion.status', $deletionRequest->confirmation_code),
            'confirmation_code' => $deletionRequest->confirmation_code,
        ]);
    }

    public function dataDeletionStatus(string $confirmationCode): View
    {
        $request = MetaDataDeletionRequest::query()
            ->where('confirmation_code', $confirmationCode)
            ->firstOrFail();

        return view('meta.data-deletion-status', array_merge($this->complianceViewData(), [
            'deletionRequest' => $request,
        ]));
    }

    private function deleteMetaLinkedData(string $appScopedUserId): array
    {
        $summary = [
            'app_scoped_user_id' => $appScopedUserId,
            'facebook_accounts_deleted' => 0,
            'facebook_pages_disconnected' => 0,
            'customer_identities_deleted' => 0,
            'conversations_deleted' => 0,
            'messages_deleted' => 0,
            'webhook_events_deleted' => 0,
            'customers_anonymized' => 0,
            'notes' => [],
        ];

        if ($appScopedUserId === '') {
            $summary['notes'][] = 'No app-scoped user id was provided by Meta.';

            return $summary;
        }

        DB::transaction(function () use ($appScopedUserId, &$summary) {
            $facebookAccounts = FacebookAccount::query()
                ->with('pages')
                ->where('facebook_user_id', $appScopedUserId)
                ->get();

            foreach ($facebookAccounts as $account) {
                foreach ($account->pages as $page) {
                    $page->update([
                        'page_access_token' => null,
                        'token_expires_at' => null,
                        'connected_status' => 'disconnected',
                        'webhook_status' => 'pending',
                        'metadata' => array_merge($page->metadata ?? [], [
                            'meta_data_deleted_at' => now()->toIso8601String(),
                            'meta_data_deleted_reason' => 'facebook_app_data_deletion',
                        ]),
                    ]);

                    $summary['facebook_pages_disconnected']++;
                }

                $account->update([
                    'facebook_user_name' => null,
                    'email' => null,
                    'access_token' => null,
                    'token_expires_at' => null,
                    'status' => 'deleted',
                    'connected_at' => null,
                    'metadata' => array_merge($account->metadata ?? [], [
                        'meta_data_deleted_at' => now()->toIso8601String(),
                        'meta_data_deleted_reason' => 'facebook_app_data_deletion',
                    ]),
                ]);

                $account->delete();
                $summary['facebook_accounts_deleted']++;
            }

            $identities = CustomerIdentity::query()
                ->where('provider', 'facebook')
                ->where('provider_user_id', $appScopedUserId)
                ->get();

            $identityIds = $identities->pluck('id')->all();
            $customerIds = $identities->pluck('customer_id')->filter()->unique()->all();
            $conversationIds = Conversation::query()
                ->whereIn('customer_identity_id', $identityIds)
                ->pluck('id')
                ->all();

            if ($conversationIds !== []) {
                $summary['messages_deleted'] += Message::query()
                    ->whereIn('conversation_id', $conversationIds)
                    ->delete();

                $summary['conversations_deleted'] += Conversation::query()
                    ->whereIn('id', $conversationIds)
                    ->forceDelete();
            }

            if ($identityIds !== []) {
                $summary['messages_deleted'] += Message::query()
                    ->whereIn('customer_identity_id', $identityIds)
                    ->delete();

                $summary['webhook_events_deleted'] += FacebookWebhookEvent::query()
                    ->where('sender_psid', $appScopedUserId)
                    ->delete();

                $summary['customer_identities_deleted'] += CustomerIdentity::query()
                    ->whereIn('id', $identityIds)
                    ->delete();
            }

            foreach ($customerIds as $customerId) {
                $customer = Customer::query()->find($customerId);

                if (! $customer) {
                    continue;
                }

                $hasRemainingFacebookIdentity = CustomerIdentity::query()
                    ->where('customer_id', $customer->id)
                    ->where('provider', 'facebook')
                    ->exists();

                if ($hasRemainingFacebookIdentity) {
                    continue;
                }

                $customer->update([
                    'facebook_name' => null,
                ]);

                $summary['customers_anonymized']++;
            }

            if (
                $summary['facebook_accounts_deleted'] === 0
                && $summary['customer_identities_deleted'] === 0
                && $summary['webhook_events_deleted'] === 0
            ) {
                $summary['notes'][] = 'No matching Facebook-linked records were found for the provided app-scoped user id.';
            }
        });

        return $summary;
    }

    private function parseSignedRequest(string $signedRequest): ?array
    {
        $secret = (string) config('services.meta.app_secret');

        if ($secret === '' || ! str_contains($signedRequest, '.')) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = explode('.', $signedRequest, 2);
        $signature = $this->base64UrlDecode($encodedSignature);
        $payloadJson = $this->base64UrlDecode($encodedPayload);

        if ($signature === false || $payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);

        if (! is_array($payload) || ($payload['algorithm'] ?? '') !== 'HMAC-SHA256') {
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $secret, true);

        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return $payload;
    }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function complianceViewData(): array
    {
        return [
            'appName' => config('app.name', 'WarehouseOps'),
            'appUrl' => rtrim((string) config('app.url'), '/'),
            'supportEmail' => config('services.meta.support_email', config('mail.from.address', 'support@warehouseops.local')),
        ];
    }
}
