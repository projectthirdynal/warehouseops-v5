<?php

namespace App\Jobs;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\Customer;
use App\Models\Upload;
use App\Models\Waybill;
use App\Services\LeadAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Generates leads from all DELIVERED waybills in a completed upload batch.
 *
 * JntWaybillFastImport uses bulk upsert (bypasses Eloquent events), so the
 * WaybillObserver never fires. This job runs post-import to fill that gap.
 */
class GenerateLeadsFromUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        private int $uploadId
    ) {}

    public function handle(LeadAuditService $auditService): void
    {
        $upload = Upload::find($this->uploadId);
        if (!$upload) {
            return;
        }

        $deliveredWaybills = Waybill::where('upload_id', $this->uploadId)
            ->where('status', 'DELIVERED')
            ->whereNotNull('receiver_phone')
            ->whereNotNull('receiver_name')
            ->get();

        $leadsCreated = 0;
        $leadsUpdated = 0;
        $skipped = 0;

        foreach ($deliveredWaybills as $waybill) {
            $phone = preg_replace('/[^0-9+]/', '', $waybill->receiver_phone ?? '');

            if (empty($phone)) {
                $skipped++;
                continue;
            }

            // Find or create customer by phone
            $customer = Customer::firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => $waybill->receiver_name,
                    'canonical_address' => $waybill->receiver_address,
                    'total_orders' => 0,
                    'successful_orders' => 0,
                    'returned_orders' => 0,
                    'success_rate' => 0,
                ]
            );

            // Update customer delivery stats
            $customer->increment('total_orders');
            $customer->increment('successful_orders');
            $customer->update([
                'success_rate' => round($customer->successful_orders / $customer->total_orders * 100, 2),
            ]);

            // Skip blacklisted customers
            if ($customer->is_blacklisted) {
                $skipped++;
                continue;
            }

            // Check for an existing non-exhausted lead for this customer
            $existingLead = Lead::where('customer_id', $customer->id)
                ->whereNotIn('pool_status', [PoolStatus::EXHAUSTED])
                ->first();

            if ($existingLead) {
                // Update product info from the newer waybill
                $existingLead->update([
                    'product_name' => $waybill->item_name ?? $existingLead->product_name,
                    'amount' => $waybill->cod_amount ?? $waybill->amount ?? $existingLead->amount,
                    'source' => 'DELIVERED_WAYBILL',
                    // Refresh to AVAILABLE if it was in cooldown and is now a returning buyer
                    'pool_status' => $existingLead->pool_status === PoolStatus::COOLDOWN
                        ? PoolStatus::AVAILABLE
                        : $existingLead->pool_status,
                ]);
                $leadsUpdated++;
            } else {
                $lead = Lead::create([
                    'customer_id' => $customer->id,
                    'name' => $waybill->receiver_name,
                    'phone' => $phone,
                    'address' => $waybill->receiver_address,
                    'city' => $waybill->city,
                    'state' => $waybill->state,
                    'barangay' => $waybill->barangay,
                    'product_name' => $waybill->item_name,
                    'amount' => $waybill->cod_amount ?? $waybill->amount,
                    'source' => 'DELIVERED_WAYBILL',
                    'pool_status' => PoolStatus::AVAILABLE,
                    'uploaded_by' => $upload->uploaded_by,
                ]);

                $auditService->log(
                    lead: $lead,
                    action: 'LEAD_CREATED',
                    metadata: [
                        'source' => 'waybill_batch_import',
                        'upload_id' => $this->uploadId,
                        'waybill_number' => $waybill->waybill_number,
                    ]
                );

                $leadsCreated++;
            }
        }

        // Record results on the upload record
        $upload->update([
            'leads_created' => $leadsCreated,
            'leads_updated' => $leadsUpdated,
        ]);
    }
}
