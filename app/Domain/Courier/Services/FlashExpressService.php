<?php

declare(strict_types=1);

namespace App\Domain\Courier\Services;

use App\Domain\Courier\Contracts\CourierServiceInterface;
use App\Domain\Courier\DTOs\CreateOrderDTO;
use App\Domain\Courier\DTOs\CreateOrderResultDTO;
use App\Domain\Courier\DTOs\TrackingResultDTO;
use App\Domain\Courier\DTOs\WebhookPayloadDTO;
use App\Domain\Courier\Models\CourierApiLog;
use App\Domain\Courier\Models\CourierProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FlashExpressService implements CourierServiceInterface
{
    private string $baseUrl;
    private string $mchId;
    private string $secretKey;
    private StatusMapper $statusMapper;
    private ?int $providerId;

    public function __construct(StatusMapper $statusMapper)
    {
        $this->baseUrl   = rtrim(config('services.couriers.flash.base_url', 'https://open-api.flashexpress.com'), '/');
        $this->mchId     = config('services.couriers.flash.mch_id', '');
        $this->secretKey = config('services.couriers.flash.secret_key', '');
        $this->statusMapper = $statusMapper;
        $this->providerId = CourierProvider::where('code', 'FLASH')->value('id');
    }

    public function getCode(): string
    {
        return 'FLASH';
    }

    // =========================================================================
    // Order creation
    // =========================================================================

    public function createOrder(CreateOrderDTO $dto): CreateOrderResultDTO
    {
        $body = [
            'mchId'            => $this->mchId,
            'nonceStr'         => $this->nonce(),
            'outTradeNo'       => $dto->waybillId ? 'WO-' . $dto->waybillId : 'WO-' . time(),
            'expressCategory'  => $dto->codAmount > 0 ? 1 : 1, // 1=Standard (confirm with Flash for PH categories)
            'articleCategory'  => 1, // 1=General merchandise
            'weight'           => $this->toGrams($dto->weight),
            'srcName'          => $dto->senderName,
            'srcPhone'         => $dto->senderPhone,
            'srcProvinceName'  => $dto->senderProvince,
            'srcCityName'      => $dto->senderCity,
            'srcDetailAddress' => $dto->senderAddress,
            'dstName'          => $dto->receiverName,
            'dstPhone'         => $dto->receiverPhone,
            'dstProvinceName'  => $dto->receiverProvince,
            'dstCityName'      => $dto->receiverCity,
            'dstDistrictName'  => $dto->receiverBarangay,
            'dstPostalCode'    => $dto->postalCode ?? '',
            'dstDetailAddress' => $dto->receiverAddress,
            'remark'           => $dto->remarks ?? '',
        ];

        // COD
        if ($dto->codAmount > 0) {
            $body['codEnabled'] = 1;
            $body['codAmount']  = $this->toCents($dto->codAmount);
        }

        // Insurance
        if ($dto->itemValue > 0) {
            $body['insured'] = 1;
            $body['insureDeclareValue'] = $this->toCents($dto->itemValue);
        }

        return $this->callApi('create_order', '/open/v1/orders', $body, $dto->waybillId, function ($data) {
            return new CreateOrderResultDTO(
                success:        true,
                trackingNumber: $data['pno'] ?? null,
                sortCode:       $data['sortCode'] ?? null,
                rawResponse:    $data,
            );
        });
    }

    // =========================================================================
    // Cancel order — PNO goes in the URL path
    // =========================================================================

    public function cancelOrder(string $trackingNumber): bool
    {
        $body = [
            'mchId'    => $this->mchId,
            'nonceStr' => $this->nonce(),
        ];

        $result = $this->callApi(
            'cancel_order',
            "/open/v1/orders/{$trackingNumber}/cancel",
            $body,
            null,
            fn () => true
        );

        return $result === true;
    }

    // =========================================================================
    // Tracking — single via /orders/{pno}/routes, batch via /orders/routesBatch
    // =========================================================================

    public function queryTracking(array $waybillNumbers): array
    {
        $results = [];

        if (count($waybillNumbers) === 1) {
            // Single tracking via URL path
            return $this->querySingleTracking($waybillNumbers[0]);
        }

        // Batch tracking via pnoList (comma-separated)
        $chunks = array_chunk($waybillNumbers, 30);

        foreach ($chunks as $chunk) {
            try {
                $body = [
                    'mchId'    => $this->mchId,
                    'nonceStr' => $this->nonce(),
                    'pnoList'  => implode(',', $chunk),
                ];

                $response = $this->makeRequest('/open/v1/orders/routesBatch', $body);

                if ($response && isset($response['data'])) {
                    $trackingList = is_array($response['data']) ? $response['data'] : [$response['data']];

                    foreach ($trackingList as $item) {
                        $pno   = $item['pno'] ?? '';
                        $state = $item['state'] ?? 0;
                        $mappedStatus = $this->statusMapper->resolve('FLASH', (int) $state);

                        $results[] = new TrackingResultDTO(
                            waybillNumber: $pno,
                            mappedStatus:  $mappedStatus,
                            courierStatus: (string) $state,
                            location:      null,
                            statusAt:      isset($item['stateChangeAt'])
                                ? (new \DateTimeImmutable())->setTimestamp((int) $item['stateChangeAt'])
                                : null,
                            rawData:       $item,
                        );
                    }
                }

                usleep(100_000); // 100ms between batch chunks
            } catch (\Exception $e) {
                Log::warning('Flash batch tracking failed', ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    private function querySingleTracking(string $pno): array
    {
        try {
            $body = [
                'mchId'    => $this->mchId,
                'nonceStr' => $this->nonce(),
            ];

            $response = $this->makeRequest("/open/v1/orders/{$pno}/routes", $body);

            if ($response && isset($response['data'])) {
                $data  = $response['data'];
                $state = $data['state'] ?? 0;
                $mappedStatus = $this->statusMapper->resolve('FLASH', (int) $state);

                return [new TrackingResultDTO(
                    waybillNumber: $data['pno'] ?? $pno,
                    mappedStatus:  $mappedStatus,
                    courierStatus: (string) $state,
                    location:      null,
                    statusAt:      isset($data['stateChangeAt'])
                        ? (new \DateTimeImmutable())->setTimestamp((int) $data['stateChangeAt'])
                        : null,
                    rawData:       $data,
                )];
            }
        } catch (\Exception $e) {
            Log::warning("Flash tracking query failed for {$pno}", ['error' => $e->getMessage()]);
        }

        return [];
    }

    // =========================================================================
    // Webhook — sign is computed from the flattened `data` object
    // =========================================================================

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->secretKey)) {
            return false;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !isset($decoded['data'])) {
            return false;
        }

        // Per Flash docs: flatten the `data` object, sort by key, append &key=SECRET, SHA256 uppercase
        $data = $decoded['data'];
        if (!is_array($data)) {
            return false;
        }

        $expected = $this->generateSign($data);

        return hash_equals($expected, strtoupper($signature));
    }

    public function parseWebhookPayload(array $data): WebhookPayloadDTO
    {
        // Webhook payload structure: { mchId, data: { pno, state, stateText, stateChangeAt }, sign }
        $inner = $data['data'] ?? $data;

        $state = $inner['state'] ?? 0;
        $mappedStatus = $this->statusMapper->resolve('FLASH', (int) $state);

        return new WebhookPayloadDTO(
            waybillNumber: $inner['pno'] ?? '',
            mappedStatus:  $mappedStatus,
            courierStatus: (string) $state,
            location:      null,
            statusAt:      isset($inner['stateChangeAt'])
                ? (new \DateTimeImmutable())->setTimestamp((int) $inner['stateChangeAt'])
                : null,
            reason:        $inner['stateText'] ?? null,
            rawData:       $data,
        );
    }

    // =========================================================================
    // Connection test
    // =========================================================================

    public function testConnection(): array
    {
        try {
            // Query warehouses — lightweight endpoint to verify credentials
            $body = [
                'mchId'    => $this->mchId,
                'nonceStr' => $this->nonce(),
            ];

            $body['sign'] = $this->generateSign($body);

            $response = Http::timeout(10)
                ->asForm()
                ->post($this->baseUrl . '/open/v1/warehouses', $body);

            $responseData = $response->json();
            $this->logApi('test_connection', '/open/v1/warehouses', $body, $responseData, $response->status(), true);

            $isSuccess = ($responseData['code'] ?? -1) == 1;

            return [
                'connected' => $isSuccess,
                'message'   => $isSuccess
                    ? 'Flash Express API connected successfully'
                    : 'API responded but returned error: ' . ($responseData['message'] ?? 'unknown'),
                'status'    => $response->status(),
            ];
        } catch (\Exception $e) {
            $this->logApi('test_connection', '/open/v1/warehouses', [], ['error' => $e->getMessage()], 0, false, $e->getMessage());

            return [
                'connected' => false,
                'message'   => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Generate Flash Express SHA256 signature (UPPERCASE).
     *
     * Per official docs:
     * 1. Remove empty/blank values and the `sign` field itself
     * 2. Sort by key name (ASCII ascending, case-sensitive)
     * 3. Concatenate as key1=value1&key2=value2&...
     * 4. Append &key=SECRET_KEY
     * 5. SHA256 → UPPERCASE
     */
    private function generateSign(array $params): string
    {
        unset($params['sign']);

        // Filter out empty/blank values
        $filtered = array_filter($params, function ($v) {
            if (is_array($v)) {
                return true; // keep arrays (they get json_encoded)
            }
            return trim((string) $v) !== '';
        });

        ksort($filtered);

        $parts = [];
        foreach ($filtered as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $parts[] = "{$key}={$value}";
        }

        $stringA = implode('&', $parts);
        $stringSignTemp = $stringA . '&key=' . $this->secretKey;

        return strtoupper(hash('sha256', $stringSignTemp));
    }

    /**
     * Send a form-encoded POST request to Flash Express API.
     */
    private function makeRequest(string $endpoint, array $body): ?array
    {
        $body['sign'] = $this->generateSign($body);

        $response = Http::timeout(30)
            ->retry(3, 500)
            ->asForm()
            ->post($this->baseUrl . $endpoint, $body);

        $data = $response->json();

        if ($response->successful() && ($data['code'] ?? -1) == 1) {
            return $data;
        }

        return null;
    }

    /**
     * Execute an API call with logging and structured error handling.
     */
    private function callApi(string $action, string $endpoint, array $body, ?int $waybillId, callable $onSuccess): mixed
    {
        $startTime = microtime(true);

        try {
            $body['sign'] = $this->generateSign($body);

            $response = Http::timeout(30)
                ->retry(3, 500)
                ->asForm()
                ->post($this->baseUrl . $endpoint, $body);

            $responseData = $response->json();
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);

            $success = $response->successful() && ($responseData['code'] ?? -1) == 1;

            $this->logApi($action, $endpoint, $body, $responseData, $response->status(), $success,
                $success ? null : ($responseData['message'] ?? 'API error'), $elapsed, $waybillId);

            if ($success) {
                return $onSuccess($responseData['data'] ?? $responseData);
            }

            $errorMsg = $responseData['message'] ?? 'Unknown error';
            Log::error("Flash Express {$action} failed", ['error' => $errorMsg, 'response' => $responseData]);

            return new CreateOrderResultDTO(
                success:      false,
                trackingNumber: null,
                errorMessage: $errorMsg,
                rawResponse:  $responseData ?? [],
            );
        } catch (\Exception $e) {
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            $this->logApi($action, $endpoint, $body, ['exception' => $e->getMessage()], 0, false, $e->getMessage(), $elapsed, $waybillId);

            Log::error("Flash Express {$action} exception", ['error' => $e->getMessage()]);

            return new CreateOrderResultDTO(
                success:      false,
                trackingNumber: null,
                errorMessage: $e->getMessage(),
                rawResponse:  [],
            );
        }
    }

    private function nonce(): string
    {
        return (string) Str::ulid();
    }

    /** Convert weight (kg float from DTO) to grams (integer for API). */
    private function toGrams(float $weightKg): int
    {
        return max(1, (int) round($weightKg * 1000));
    }

    /** Convert PHP amount (float) to cents (integer for API). */
    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function logApi(string $action, string $endpoint, array $requestData, ?array $responseData, int $httpStatus, bool $success, ?string $error = null, ?float $elapsed = null, ?int $waybillId = null): void
    {
        try {
            // Redact the secret key and sign from logged request data
            $logData = $requestData;
            unset($logData['sign']);

            CourierApiLog::create([
                'courier_provider_id' => $this->providerId,
                'courier_code'        => 'FLASH',
                'action'              => $action,
                'direction'           => 'outbound',
                'endpoint'            => $this->baseUrl . $endpoint,
                'request_data'        => $logData,
                'response_data'       => $responseData,
                'http_status'         => $httpStatus,
                'is_success'          => $success,
                'error_message'       => $error,
                'response_time_ms'    => $elapsed,
                'waybill_id'          => $waybillId,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log Flash API call', ['error' => $e->getMessage()]);
        }
    }
}
