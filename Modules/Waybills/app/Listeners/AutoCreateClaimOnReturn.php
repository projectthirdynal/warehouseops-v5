<?php

declare(strict_types=1);

namespace Modules\Waybills\Listeners;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;
use Modules\Couriers\Events\TrackingStatusUpdated;
use Modules\Waybills\Enums\ClaimStatus;
use Modules\Waybills\Enums\ClaimType;
use Modules\Waybills\Enums\WaybillStatus;
use Modules\Waybills\Models\Claim;

class AutoCreateClaimOnReturn
{
    public function handle(TrackingStatusUpdated $event): void
    {
        if ($event->payload->mappedStatus !== WaybillStatus::RETURNED) {
            return;
        }

        $enabled = SiteSetting::get('claim_auto_create_enabled', '1') === '1';
        if (! $enabled) {
            return;
        }

        $waybill = $event->waybill;

        $existingClaim = Claim::where('waybill_id', $waybill->id)->exists();
        if ($existingClaim) {
            return;
        }

        try {
            $claimAmount = (float) ($waybill->cod_amount ?? $waybill->amount ?? 0);

            $claim = Claim::create([
                'claim_number' => Claim::generateClaimNumber(),
                'waybill_id' => $waybill->id,
                'type' => ClaimType::BEYOND_SLA->value,
                'status' => ClaimStatus::DRAFT->value,
                'auto_created' => true,
                'source' => 'tracking_sync',
                'description' => $this->buildDescription($waybill),
                'claim_amount' => $claimAmount,
                'filed_by' => $waybill->uploaded_by ?? 1,
            ]);

            Log::info("Auto-created claim {$claim->claim_number} for returned waybill {$waybill->waybill_number}");
        } catch (\Throwable $e) {
            Log::error("Failed to auto-create claim for waybill {$waybill->waybill_number}: {$e->getMessage()}");
        }
    }

    protected function buildDescription($waybill): string
    {
        $parts = [
            'Auto-created: Waybill marked as RETURNED.',
            "Waybill: {$waybill->waybill_number}",
            'Courier: '.($waybill->courier_provider ?? 'Unknown'),
        ];

        if ($waybill->rts_reason) {
            $parts[] = "RTS Reason: {$waybill->rts_reason}";
        }

        if ($waybill->returned_at) {
            $parts[] = "Returned at: {$waybill->returned_at}";
        }

        return implode(' ', $parts);
    }
}
