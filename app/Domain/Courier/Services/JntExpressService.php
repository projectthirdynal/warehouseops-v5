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

class JntExpressService implements CourierServiceInterface
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private string $webhookSecret;
    private StatusMapper $statusMapper;
    private ?int $providerId;

    public function __construct(StatusMapper $statusMapper)
    {
        $this->baseUrl = config('services.couriers.jnt.base_url', 'https://openapi.jtexpress.ph/api');
        $this->apiKey = config('services.couriers.jnt.api_key') ?? '';
        $this->apiSecret = config('services.couriers.jnt.api_secret') ?? '';
        $this->webhookSecret = config('services.couriers.jnt.webhook_secret') ?? '';
        $this->statusMapper = $statusMapper;
        $this->providerId = CourierProvider::where('code', 'JNT')->value('id');
    }

    public function getCode(): string
    {
        return 'JNT';
    }

    public function createOrder(CreateOrderDTO $dto): CreateOrderResultDTO
    {
        $body = [
            'customerCode' => $this->apiKey,
            'digest'       => '', // will be set by sign()
            'txlogisticId' => $dto->waybillId ? 'WO-' . $dto->waybillId : 'WO-' . time(),
            'orderType'    => 1,
            'serviceType'  => 1,
            'payType'      => $dto->codAmount > 0 ? 1 : 3, // 1=COD, 3=prepaid
            'sender'       => [
                'name'     => $dto->senderName,
                'mobile'   => $dto->senderPhone,
                'prov'     => $dto->senderProvince,
                'city'     => $dto->senderCity,
                'address'  => $dto->senderAddress,
            ],
            'receiver'     => [
                'name'     => $dto->receiverName,
                'mobile'   => $dto->receiverPhone,
                'prov'     => $dto->receiverProvince,
                'city'     => $dto->receiverCity,
                'address'  => $dto->receiverAddress,
            ],
            'weight'       => max(0.1, $dto->weight),
            'itemsValue'   => $dto->itemValue,
            'goodsType'    => 1,
            'totalQuantity' => $dto->itemQty,
            'remark'       => $dto->remarks ?? '',
        ];

        if ($dto->codAmount > 0) {
            $body['codAmount'] = $dto->codAmount;
        }

        return $this->callApi('create_order', '/webopenplatformapi/api/order/addOrder', $body, $dto->waybillId, function ($data) {
            return new CreateOrderResultDTO(
                success:        true,
                trackingNumber: $data['billCode'] ?? $data['waybillNo'] ?? null,
                sortCode:       $data['sortingCode'] ?? null,
                rawResponse:    $data,
            );
        });
    }

    public function cancelOrder(string $trackingNumber): bool
    {
        $body = [
            'customerCode' => $this->apiKey,
            'billCode'     => $trackingNumber,
            'reason'       => 'Cancelled by system',
        ];

        $result = $this->callApi('cancel_order', '/webopenplatformapi/api/order/cancelOrder', $body, null, fn ($data) => true);

        return $result === true || ($result instanceof CreateOrderResultDTO && $result->success);
    }

    public function queryTracking(array $waybillNumbers): array
    {
        $results = [];

        // J&T supports batch of 30 waybills per request
        $chunks = array_chunk($waybillNumbers, 30);

        foreach ($chunks as $chunk) {
            try {
                $body = [
                    'customerCode' => $this->apiKey,
                    'billCodes'    => implode(',', $chunk),
                    'lang'         => 'en',
                ];

                $response = $this->makeRequest('/webopenplatformapi/api/track/getTrackByBillCodes', $body);

                if ($response && isset($response['data'])) {
                    $trackingList = is_array($response['data']) ? $response['data'] : [$response['data']];

                    foreach ($trackingList as $item) {
                        $billCode = $item['billCode'] ?? '';
                        $details = $item['details'] ?? [];
                        $latest = !empty($details) ? $details[0] : null; // Most recent tracking event

                        if ($latest) {
                            $courierStatus = $latest['scanType'] ?? $latest['logisticsStatus'] ?? '';
                            $mappedStatus = $this->statusMapper->resolve('JNT', $courierStatus);

                            $results[] = new TrackingResultDTO(
                                waybillNumber: $billCode,
                                mappedStatus:  $mappedStatus,
                                courierStatus: $courierStatus,
                                location:      $latest['scanNetworkName'] ?? $latest['scanCity'] ?? null,
                                statusAt:      isset($latest['scanTime']) ? new \DateTimeImmutable($latest['scanTime']) : null,
                                rawData:       $item,
                            );
                        }
                    }
                }

                usleep(200_000); // 200ms between batches
            } catch (\Exception $e) {
                Log::warning('J&T tracking query failed for batch', [
                    'count' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->webhookSecret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhookPayload(array $data): WebhookPayloadDTO
    {
        $courierStatus = $data['logisticsStatus'] ?? $data['scanType'] ?? $data['orderStatus'] ?? '';
        $mappedStatus = $this->statusMapper->resolve('JNT', $courierStatus);

        return new WebhookPayloadDTO(
            waybillNumber: $data['billCode'] ?? $data['txlogisticId'] ?? '',
            mappedStatus:  $mappedStatus,
            courierStatus: $courierStatus,
            location:      $data['scanNetworkName'] ?? $data['scanCity'] ?? null,
            statusAt:      isset($data['scanTime']) ? new \DateTimeImmutable($data['scanTime']) : null,
            reason:        $data['desc'] ?? $data['remark'] ?? null,
            rawData:       $data,
        );
    }

    public function testConnection(): array
    {
        try {
            $body = [
                'customerCode' => $this->apiKey,
                'billCodes'    => 'TEST000000000',
                'lang'         => 'en',
            ];

            $bizContent = json_encode($body);
            $digest = $this->sign($bizContent);

            $response = Http::timeout(10)
                ->asForm()
                ->post($this->baseUrl . '/webopenplatformapi/api/track/getTrackByBillCodes', [
                    'bizContent' => $bizContent,
                    'digest'     => $digest,
                ]);

            $this->logApi('test_connection', '/track/getTrackByBillCodes', $body, $response->json(), $response->status(), true);

            return [
                'connected' => true,
                'message'   => 'J&T Express API is reachable',
                'status'    => $response->status(),
            ];
        } catch (\Exception $e) {
            $this->logApi('test_connection', '/track/getTrackByBillCodes', [], ['error' => $e->getMessage()], 0, false, $e->getMessage());

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
     * J&T digest signature: base64(hmac-sha256(base64(md5(bizContent)), apiSecret))
     */
    private function sign(string $bizContent): string
    {
        $md5 = base64_encode(md5($bizContent, true));

        return base64_encode(hash_hmac('sha256', $md5, $this->apiSecret, true));
    }

    private function makeRequest(string $endpoint, array $body): ?array
    {
        $bizContent = json_encode($body);
        $digest = $this->sign($bizContent);

        $response = Http::timeout(30)
            ->retry(3, 500)
            ->asForm()
            ->post($this->baseUrl . $endpoint, [
                'bizContent' => $bizContent,
                'digest'     => $digest,
            ]);

        $data = $response->json();

        if ($response->successful() && (($data['code'] ?? '') == '1' || ($data['code'] ?? -1) == 1)) {
            return $data;
        }

        return null;
    }

    private function callApi(string $action, string $endpoint, array $body, ?int $waybillId, callable $onSuccess): mixed
    {
        $startTime = microtime(true);

        try {
            $bizContent = json_encode($body);
            $digest = $this->sign($bizContent);

            $response = Http::timeout(30)
                ->retry(3, 500)
                ->asForm()
                ->post($this->baseUrl . $endpoint, [
                    'bizContent' => $bizContent,
                    'digest'     => $digest,
                ]);

            $responseData = $response->json();
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);

            $success = $response->successful() && (($responseData['code'] ?? '') == '1' || ($responseData['code'] ?? -1) == 1);

            $this->logApi($action, $endpoint, $body, $responseData, $response->status(), $success,
                $success ? null : ($responseData['msg'] ?? $responseData['message'] ?? 'API error'), $elapsed, $waybillId);

            if ($success) {
                return $onSuccess($responseData['data'] ?? $responseData);
            }

            $errorMsg = $responseData['msg'] ?? $responseData['message'] ?? 'Unknown error';
            Log::error("J&T Express {$action} failed", ['error' => $errorMsg, 'response' => $responseData]);

            return new CreateOrderResultDTO(
                success:      false,
                trackingNumber: null,
                errorMessage: $errorMsg,
                rawResponse:  $responseData ?? [],
            );
        } catch (\Exception $e) {
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            $this->logApi($action, $endpoint, $body, ['exception' => $e->getMessage()], 0, false, $e->getMessage(), $elapsed, $waybillId);

            Log::error("J&T Express {$action} exception", ['error' => $e->getMessage()]);

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
                'courier_code'        => 'JNT',
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
            Log::warning('Failed to log J&T API call', ['error' => $e->getMessage()]);
        }
    }
}
