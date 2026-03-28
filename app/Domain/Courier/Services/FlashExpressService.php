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

class FlashExpressService implements CourierServiceInterface
{
    private string $baseUrl;
    private string $apiKey;
    private string $webhookSecret;
    private StatusMapper $statusMapper;
    private ?int $providerId;

    public function __construct(StatusMapper $statusMapper)
    {
        $this->baseUrl = config('services.couriers.flash.base_url', 'https://open.flashexpress.ph/open/v3');
        $this->apiKey = config('services.couriers.flash.api_key', '');
        $this->webhookSecret = config('services.couriers.flash.webhook_secret', '');
        $this->statusMapper = $statusMapper;
        $this->providerId = CourierProvider::where('code', 'FLASH')->value('id');
    }

    public function getCode(): string
    {
        return 'FLASH';
    }

    public function createOrder(CreateOrderDTO $dto): CreateOrderResultDTO
    {
        $body = [
            'mchId'            => $this->apiKey,
            'nonceStr'         => str_replace('-', '', (string) \Illuminate\Support\Str::uuid()),
            'outTradeNo'       => $dto->waybillId ? 'WO-' . $dto->waybillId : 'WO-' . time(),
            'srcName'          => $dto->senderName,
            'srcPhone'         => $dto->senderPhone,
            'srcProvinceName'  => $dto->senderProvince,
            'srcCityName'      => $dto->senderCity,
            'srcDetailAddress' => $dto->senderAddress,
            'dstName'          => $dto->receiverName,
            'dstPhone'         => $dto->receiverPhone,
            'dstProvinceName'  => $dto->receiverProvince,
            'dstCityName'      => $dto->receiverCity,
            'dstBarangayName'  => $dto->receiverBarangay,
            'dstPostalCode'    => $dto->postalCode ?? '',
            'dstDetailAddress' => $dto->receiverAddress,
            'articleCategory'  => 2, // 2 = parcel
            'expressCategory'  => $dto->codAmount > 0 ? 2 : 1, // 2=COD, 1=standard
            'weight'           => max(0.1, $dto->weight),
            'codAmount'        => $dto->codAmount > 0 ? $dto->codAmount : null,
            'insureDeclareValue' => $dto->itemValue,
            'remark'           => $dto->remarks ?? '',
        ];

        return $this->callApi('create_order', '/orders', $body, $dto->waybillId, function ($data) {
            return new CreateOrderResultDTO(
                success:        true,
                trackingNumber: $data['pno'] ?? null,
                sortCode:       $data['sortCode'] ?? null,
                rawResponse:    $data,
            );
        });
    }

    public function cancelOrder(string $trackingNumber): bool
    {
        $body = [
            'mchId'    => $this->apiKey,
            'nonceStr' => bin2hex(random_bytes(16)),
            'pno'      => $trackingNumber,
        ];

        $result = $this->callApi('cancel_order', '/orders/cancel', $body, null, fn ($data) => true);

        return $result instanceof CreateOrderResultDTO ? $result->success : (bool) $result;
    }

    public function queryTracking(array $waybillNumbers): array
    {
        $results = [];

        // Flash tracking is per-waybill
        foreach ($waybillNumbers as $number) {
            try {
                $body = [
                    'mchId'    => $this->apiKey,
                    'nonceStr' => bin2hex(random_bytes(16)),
                    'pno'      => $number,
                ];

                $response = $this->makeRequest('/orders/trace', $body);

                if ($response && isset($response['data'])) {
                    $data = $response['data'];
                    $courierStatus = $data['state'] ?? $data['status'] ?? '';
                    $mappedStatus = $this->statusMapper->resolve('FLASH', (int) $courierStatus);

                    $results[] = new TrackingResultDTO(
                        waybillNumber: $number,
                        mappedStatus:  $mappedStatus,
                        courierStatus: (string) $courierStatus,
                        location:      $data['location'] ?? null,
                        statusAt:      isset($data['updatedAt']) ? new \DateTimeImmutable($data['updatedAt']) : null,
                        rawData:       $data,
                    );
                }

                usleep(100_000); // 100ms between requests to respect rate limits
            } catch (\Exception $e) {
                Log::warning("Flash tracking query failed for {$number}", ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->webhookSecret)) {
            return false;
        }

        // Verify by reconstructing the signature from the payload params
        $params = json_decode($payload, true);
        if (!is_array($params)) {
            return false;
        }

        // Use webhook secret as the signing key for inbound verification
        unset($params['sign']);
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
        ksort($params);

        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $parts[] = "{$key}={$value}";
        }
        $queryString = implode('&', $parts) . '&key=' . $this->webhookSecret;
        $expected = hash('sha256', $queryString);

        return hash_equals($expected, $signature);
    }

    public function parseWebhookPayload(array $data): WebhookPayloadDTO
    {
        $courierStatus = $data['state'] ?? $data['status'] ?? 0;
        $mappedStatus = $this->statusMapper->resolve('FLASH', (int) $courierStatus);

        return new WebhookPayloadDTO(
            waybillNumber: $data['pno'] ?? '',
            mappedStatus:  $mappedStatus,
            courierStatus: (string) $courierStatus,
            location:      $data['location'] ?? null,
            statusAt:      isset($data['updatedAt']) ? new \DateTimeImmutable($data['updatedAt']) : null,
            reason:        $data['failedReason'] ?? $data['remark'] ?? null,
            rawData:       $data,
        );
    }

    public function testConnection(): array
    {
        try {
            // Use a tracking query with a dummy number to verify connectivity
            $body = [
                'mchId'    => $this->apiKey,
                'nonceStr' => bin2hex(random_bytes(16)),
                'pno'      => 'TEST000000000',
            ];

            $body['sign'] = $this->sign($body);

            $response = Http::timeout(10)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->baseUrl . '/orders/trace', $body);

            $this->logApi('test_connection', '/orders/trace', $body, $response->json(), $response->status(), true);

            return [
                'connected' => true,
                'message'   => 'Flash Express API is reachable',
                'status'    => $response->status(),
            ];
        } catch (\Exception $e) {
            $this->logApi('test_connection', '/orders/trace', [], ['error' => $e->getMessage()], 0, false, $e->getMessage());

            return [
                'connected' => false,
                'message'   => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Generate Flash Express SHA256 signature.
     * Sort params alphabetically, concatenate as key=value&, append &key=API_SECRET.
     */
    private function sign(array $params): string
    {
        // Remove 'sign' if present
        unset($params['sign']);

        // Filter out empty values
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);

        // Sort alphabetically by key
        ksort($params);

        // Build query string
        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $parts[] = "{$key}={$value}";
        }
        $queryString = implode('&', $parts) . '&key=' . $this->apiKey;

        return hash('sha256', $queryString);
    }

    private function makeRequest(string $endpoint, array $body): ?array
    {
        $body['sign'] = $this->sign($body);

        $response = Http::timeout(30)
            ->retry(3, 500)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($this->baseUrl . $endpoint, $body);

        $data = $response->json();

        if ($response->successful() && ($data['code'] ?? -1) == 1) {
            return $data;
        }

        return null;
    }

    private function callApi(string $action, string $endpoint, array $body, ?int $waybillId, callable $onSuccess): mixed
    {
        $startTime = microtime(true);

        try {
            $body['sign'] = $this->sign($body);

            $response = Http::timeout(30)
                ->retry(3, 500)
                ->withHeaders(['Content-Type' => 'application/json'])
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

    private function logApi(string $action, string $endpoint, array $requestData, ?array $responseData, int $httpStatus, bool $success, ?string $error = null, ?float $elapsed = null, ?int $waybillId = null): void
    {
        try {
            CourierApiLog::create([
                'courier_provider_id' => $this->providerId,
                'courier_code'        => 'FLASH',
                'action'              => $action,
                'direction'           => 'outbound',
                'endpoint'            => $this->baseUrl . $endpoint,
                'request_data'        => $requestData,
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
