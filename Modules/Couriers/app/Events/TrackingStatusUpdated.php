<?php

declare(strict_types=1);

namespace Modules\Couriers\Events;

use Modules\Couriers\DTOs\WebhookPayloadDTO;
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
