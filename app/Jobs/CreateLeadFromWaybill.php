<?php

namespace App\Jobs;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use Modules\Waybills\Enums\WaybillStatus;
use Modules\Waybills\Models\Waybill;
use App\Models\Customer;
use App\Services\LeadAuditService;
use App\Services\LeadScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateLeadFromWaybill implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $waybillId
    ) {}

    public function handle(LeadAuditService $auditService, LeadScoringService $scoringService): void
    {
        $waybill = Waybill::find($this->waybillId);

        if (! $waybill || $waybill->status !== WaybillStatus::DELIVERED) {
            return;
        }

        // Find or create customer
        $customer = Customer::firstOrCreate(
            ['phone' => $waybill->receiver_phone],
            [
                'name' => $waybill->receiver_name,
                'canonical_address' => $waybill->receiver_address,
                'total_orders' => 0,
                'successful_orders' => 0,
                'returned_orders' => 0,
            ]
        );

        // Update customer stats
        $customer->increment('total_orders');
        $customer->increment('successful_orders');
        $customer->update([
            'success_rate' => $customer->total_orders > 0
                ? round($customer->successful_orders / $customer->total_orders * 100, 2)
                : 0,
        ]);

        if ($customer->is_blacklisted) {
            return;
        }

        // Find or create lead
        $lead = Lead::where('customer_id', $customer->id)
            ->where('pool_status', '!=', PoolStatus::EXHAUSTED)
            ->first();

        $qualityScore = $scoringService->scoreFromImportData([
            'source' => 'DELIVERED_WAYBILL',
            'address' => $waybill->receiver_address,
            'city' => $waybill->city ?? null,
            'state' => $waybill->state ?? null,
            'barangay' => $waybill->barangay ?? null,
            'phone' => $waybill->receiver_phone,
            'product_name' => $waybill->item_name,
            'amount' => $waybill->amount,
        ], $customer);

        if ($lead) {
            $lead->update([
                'product_name' => $waybill->item_name,
                'amount' => $waybill->amount,
                'source' => 'DELIVERED_WAYBILL',
                'quality_score' => $qualityScore,
                'last_scored_at' => now(),
            ]);
        } else {
            $lead = Lead::create([
                'customer_id' => $customer->id,
                'name' => $waybill->receiver_name,
                'phone' => $waybill->receiver_phone,
                'address' => $waybill->receiver_address,
                'city' => $waybill->city ?? null,
                'state' => $waybill->state ?? null,
                'barangay' => $waybill->barangay ?? null,
                'product_name' => $waybill->item_name,
                'amount' => $waybill->amount,
                'source' => 'DELIVERED_WAYBILL',
                'pool_status' => PoolStatus::AVAILABLE,
                'quality_score' => $qualityScore,
                'last_scored_at' => now(),
            ]);

            $auditService->log(
                lead: $lead,
                action: 'LEAD_CREATED',
                metadata: ['source' => 'waybill', 'waybill_id' => $waybill->id]
            );
        }
    }
}
