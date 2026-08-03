<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaApiClient
{
    private const BASE_URL = 'https://graph.facebook.com';

    private const RATE_LIMIT_CACHE_KEY = 'meta_api:rate_limit';

    private const RATE_LIMIT_WINDOW = 3600;

    private const MAX_CALLS_PER_WINDOW = 180;

    public function __construct(
        private readonly string $graphVersion,
        private readonly string $appId,
        private readonly string $appSecret,
    ) {}

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    public function post(string $endpoint, array $params = []): array
    {
        return $this->request('POST', $endpoint, $params);
    }

    public function delete(string $endpoint, array $params = []): array
    {
        return $this->request('DELETE', $endpoint, $params);
    }

    private function request(string $method, string $endpoint, array $params): array
    {
        $this->checkRateLimit();

        $url = $this->buildUrl($endpoint);
        $maxRetries = 3;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = match ($method) {
                    'GET' => Http::get($url, $params),
                    'POST' => Http::post($url, $params),
                    'DELETE' => Http::delete($url, $params),
                };

                $this->incrementRateLimit();

                if ($response->successful()) {
                    return $response->json() ?? [];
                }

                if ($response->status() === 429 || $response->status() === 403) {
                    $retryAfter = (int) ($response->header('X-RateLimit-Reset') ?? 60);

                    if ($attempt < $maxRetries) {
                        Log::warning('Meta API rate limited, backing off', [
                            'endpoint' => $endpoint,
                            'retry_after' => $retryAfter,
                            'attempt' => $attempt,
                        ]);

                        $this->setRateLimited($retryAfter);
                        sleep(min($retryAfter, 60));

                        continue;
                    }
                }

                if ($response->status() >= 500 && $attempt < $maxRetries) {
                    $backoff = (int) (pow(2, $attempt) * 1000);
                    usleep($backoff * 1000);

                    continue;
                }

                $response->throw();
            } catch (ConnectionException $e) {
                if ($attempt < $maxRetries) {
                    $backoff = (int) (pow(2, $attempt) * 1000);
                    usleep($backoff * 1000);

                    continue;
                }

                throw $e;
            }
        }
    }

    private function buildUrl(string $endpoint): string
    {
        $base = self::BASE_URL.'/'.$this->graphVersion;

        if (str_starts_with($endpoint, '/')) {
            return $base.$endpoint;
        }

        return $base.'/'.$endpoint;
    }

    private function checkRateLimit(): void
    {
        $rateLimitedUntil = Cache::get(self::RATE_LIMIT_CACHE_KEY.':blocked_until');

        if ($rateLimitedUntil && now()->timestamp < (int) $rateLimitedUntil) {
            $wait = (int) $rateLimitedUntil - now()->timestamp;
            Log::info('Meta API rate limit active, waiting', ['wait_seconds' => $wait]);
            sleep(min($wait, 30));
        }
    }

    private function incrementRateLimit(): void
    {
        $key = self::RATE_LIMIT_CACHE_KEY.':count';
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, self::RATE_LIMIT_WINDOW);
    }

    private function setRateLimited(int $seconds): void
    {
        Cache::put(
            self::RATE_LIMIT_CACHE_KEY.':blocked_until',
            now()->timestamp + $seconds,
            $seconds + 60,
        );
    }
}
