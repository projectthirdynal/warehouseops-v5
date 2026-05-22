<?php

declare(strict_types=1);

namespace App\Domain\Courier\Events;

use App\Domain\Courier\DTOs\WebhookPayloadDTO;
use App\Models\Waybill;
use Illuminate\Foundation\Events\Dispatchable;

class TrackingStatusUpdated
{
    use Dispatchable;

    public function __construct(
        public Waybill $waybill,
        public WebhookPayloadDTO $payload,
    ) {}
}
