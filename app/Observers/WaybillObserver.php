<?php

namespace App\Observers;

use Modules\Waybills\Enums\WaybillStatus;
use Modules\Waybills\Models\Waybill;
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
