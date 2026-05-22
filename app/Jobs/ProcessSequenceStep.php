<?php

namespace App\Jobs;

use App\Models\SmsSequenceEnrollment;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSequenceStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public SmsSequenceEnrollment $enrollment
    ) {
    }

    public function handle(SmsService $smsService): void
    {
        // Check if enrollment is still active
        if ($this->enrollment->status !== 'active') {
            return;
        }

        // Get the current step
        $step = $this->enrollment->sequence->steps()
            ->where('step_order', $this->enrollment->current_step)
            ->where('is_active', true)
            ->first();

        if (!$step) {
            $this->enrollment->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            return;
        }

        // Build recipient data for personalization
        $recipient = [
            'phone' => $this->enrollment->phone,
        ];

        if ($this->enrollment->waybill) {
            $recipient['name'] = $this->enrollment->waybill->receiver_name;
            $recipient['waybill_number'] = $this->enrollment->waybill->waybill_number;
            $recipient['waybill_id'] = $this->enrollment->waybill_id;
            $recipient['status'] = $this->enrollment->waybill->status;
            $recipient['cod_amount'] = $this->enrollment->waybill->cod_amount;
        }

        if ($this->enrollment->lead) {
            $recipient['name'] = $this->enrollment->lead->name;
            $recipient['lead_id'] = $this->enrollment->lead_id;
        }

        // Send the message
        $message = $smsService->personalizeMessage($step->message, $recipient);

        $smsService->send($this->enrollment->phone, $message, [
            'sequence_id' => $this->enrollment->sequence_id,
            'waybill_id' => $this->enrollment->waybill_id,
            'lead_id' => $this->enrollment->lead_id,
        ]);

        // Advance to next step
        $this->enrollment->advanceStep();

        // Schedule next step if not completed
        if ($this->enrollment->status === 'active' && $this->enrollment->next_step_at) {
            ProcessSequenceStep::dispatch($this->enrollment)
                ->delay($this->enrollment->next_step_at);
        }
    }
}
