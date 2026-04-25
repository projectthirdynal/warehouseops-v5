<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\QboConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin wrapper around QuickBooks Online REST API v3.
 * - Handles token refresh automatically
 * - Passes idempotency_key as RequestId on every write to prevent duplicates
 * - All HTTP failures bubble up to QboSyncJob for retry
 *
 * Configure via .env:
 *   QBO_CLIENT_ID, QBO_CLIENT_SECRET, QBO_ENVIRONMENT (sandbox|production)
 */
class QboClient
{
    private const DISCOVERY = [
        'sandbox'    => 'https://sandbox-quickbooks.api.intuit.com',
        'production' => 'https://quickbooks.api.intuit.com',
    ];

    private const OAUTH_TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    private const MINOR_VERSION   = 70;

    private QboConnection $connection;

    public function __construct(?QboConnection $connection = null)
    {
        $resolved = $connection ?? QboConnection::active();
        if (! $resolved) {
            throw new RuntimeException('No active QuickBooks Online connection. Connect via /finance/quickbooks first.');
        }
        $this->connection = $resolved;
        $this->ensureFreshToken();
    }

    /**
     * Refresh the access token if it expires within 10 minutes.
     */
    public function ensureFreshToken(): void
    {
        if (! $this->connection->expiresWithinMinutes(10)) return;

        $clientId     = (string) config('services.qbo.client_id');
        $clientSecret = (string) config('services.qbo.client_secret');

        $resp = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(self::OAUTH_TOKEN_URL, [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->connection->refresh_token,
            ]);

        if (! $resp->successful()) {
            Log::error('QBO token refresh failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            throw new RuntimeException('QBO token refresh failed: ' . $resp->body());
        }

        $data = $resp->json();
        $this->connection->update([
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $this->connection->refresh_token,
            'expires_at'    => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ]);
    }

    /**
     * Create a QBO entity. Idempotency key is passed as RequestId — QBO returns the
     * existing entity on retry instead of duplicating.
     */
    public function create(string $entity, array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', $entity, $payload, $idempotencyKey);
    }

    public function update(string $entity, array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', $entity, $payload, $idempotencyKey);
    }

    public function query(string $sql): array
    {
        $base = self::DISCOVERY[$this->connection->environment === 'PRODUCTION' ? 'production' : 'sandbox'];
        $url  = "{$base}/v3/company/{$this->connection->realm_id}/query";

        $resp = Http::withToken($this->connection->access_token)
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/text'])
            ->withBody($sql, 'application/text')
            ->post($url . '?minorversion=' . self::MINOR_VERSION);

        $this->throwOnFailure($resp);
        return $resp->json();
    }

    private function request(string $method, string $entity, array $payload, string $idempotencyKey): array
    {
        $base = self::DISCOVERY[$this->connection->environment === 'PRODUCTION' ? 'production' : 'sandbox'];
        $url  = "{$base}/v3/company/{$this->connection->realm_id}/{$entity}";
        $url .= '?minorversion=' . self::MINOR_VERSION . '&requestid=' . urlencode($idempotencyKey);

        $resp = Http::withToken($this->connection->access_token)
            ->withHeaders(['Accept' => 'application/json'])
            ->send($method, $url, ['json' => $payload]);

        $this->throwOnFailure($resp);
        return $resp->json();
    }

    private function throwOnFailure(Response $resp): void
    {
        if ($resp->successful()) return;
        $msg = "QBO API error {$resp->status()}: " . $resp->body();
        Log::error($msg);
        throw new RuntimeException($msg);
    }
}
