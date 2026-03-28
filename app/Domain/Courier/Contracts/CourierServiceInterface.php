<?php

declare(strict_types=1);

namespace App\Domain\Courier\Contracts;

use App\Domain\Courier\DTOs\CreateOrderDTO;
use App\Domain\Courier\DTOs\CreateOrderResultDTO;
use App\Domain\Courier\DTOs\TrackingResultDTO;
use App\Domain\Courier\DTOs\WebhookPayloadDTO;

interface CourierServiceInterface
{
    /** Create a shipment order and return the tracking/waybill number. */
    public function createOrder(CreateOrderDTO $dto): CreateOrderResultDTO;

    /** Cancel an order by tracking number. */
    public function cancelOrder(string $trackingNumber): bool;

    /**
     * Query tracking status for one or more waybill numbers.
     *
     * @param  string[] $waybillNumbers
     * @return TrackingResultDTO[]
     */
    public function queryTracking(array $waybillNumbers): array;

    /** Verify a webhook signature from this courier. */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /** Parse the courier's webhook payload into a normalized DTO. */
    public function parseWebhookPayload(array $data): WebhookPayloadDTO;

    /** Health check / connectivity test. */
    public function testConnection(): array;

    /** Return the courier code (e.g. 'FLASH', 'JNT'). */
    public function getCode(): string;
}
