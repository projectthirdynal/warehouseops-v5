<?php

declare(strict_types=1);

namespace Modules\Couriers\Events;

use App\Models\Waybill;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\Couriers\DTOs\WebhookPayloadDTO;

class TrackingStatusUpdated
{
    use Dispatchable;

    public function __construct(
        public Waybill $waybill,
        public WebhookPayloadDTO $payload,
    ) {}
}
