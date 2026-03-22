<?php

namespace App\Observers;

use App\Domain\Waybill\Enums\WaybillStatus;
use App\Domain\Waybill\Models\Waybill;
use App\Jobs\CreateLeadFromWaybill;

class WaybillObserver
{
    public function updated(Waybill $waybill): void
    {
        if ($waybill->isDirty('status') && $waybill->status === WaybillStatus::DELIVERED) {
            CreateLeadFromWaybill::dispatch($waybill->id);
        }
    }
}
