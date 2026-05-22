<?php

namespace App\Services;

use App\Jobs\ProcessSequenceStep;
use App\Models\Lead;
use App\Models\SmsSequence;
use App\Models\SmsSequenceEnrollment;
use App\Models\Waybill;

class SmsSequenceService
{
    /**
     * Trigger sequences based on an event
     */
    public function trigger(string $event, Waybill|Lead|null $model = null): void
    {
        $sequences = SmsSequence::where('trigger_event', $event)
            ->where('is_active', true)
            ->with(['steps' => fn($q) => $q->where('is_active', true)->orderBy('step_order')])
            ->get();

        foreach ($sequences as $sequence) {
            if ($sequence->steps->isEmpty()) {
                continue;
            }

            $this->enroll($sequence, $model);
        }
    }

    /**
     * Enroll a recipient in a sequence
     */
    public function enroll(SmsSequence $sequence, Waybill|Lead|null $model): void
    {
        $phone = null;
        $waybillId = null;
        $leadId = null;

        if ($model instanceof Waybill) {
            $phone = $model->receiver_phone;
            $waybillId = $model->id;
        } elseif ($model instanceof Lead) {
            $phone = $model->phone;
            $leadId = $model->id;
        }

        if (!$phone) {
            return;
        }

        // Check if already enrolled in this sequence
        $existingEnrollment = SmsSequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('phone', $phone)
            ->whereIn('status', ['active', 'completed'])
            ->where('created_at', '>=', now()->subDays(7)) // Within 7 days
            ->exists();

        if ($existingEnrollment) {
            return;
        }

        // Get first step
        $firstStep = $sequence->steps->first();

        $enrollment = SmsSequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'waybill_id' => $waybillId,
            'lead_id' => $leadId,
            'phone' => $phone,
            'current_step' => $firstStep->step_order,
            'status' => 'active',
            'next_step_at' => now()->addMinutes($firstStep->getDelayInMinutes()),
        ]);

        // If first step has no delay, process immediately
        if ($firstStep->delay_minutes === 0 || $firstStep->step_order === 1) {
            ProcessSequenceStep::dispatch($enrollment);
        } else {
            ProcessSequenceStep::dispatch($enrollment)
                ->delay($enrollment->next_step_at);
        }
    }

    /**
     * Cancel enrollment for a recipient
     */
    public function cancel(string $phone, ?int $sequenceId = null): int
    {
        $query = SmsSequenceEnrollment::where('phone', $phone)
            ->where('status', 'active');

        if ($sequenceId) {
            $query->where('sequence_id', $sequenceId);
        }

        return $query->update([
            'status' => 'cancelled',
        ]);
    }

    /**
     * Pause all active enrollments for a recipient
     */
    public function pause(string $phone): int
    {
        return SmsSequenceEnrollment::where('phone', $phone)
            ->where('status', 'active')
            ->update(['status' => 'paused']);
    }

    /**
     * Resume paused enrollments
     */
    public function resume(string $phone): int
    {
        $enrollments = SmsSequenceEnrollment::where('phone', $phone)
            ->where('status', 'paused')
            ->get();

        foreach ($enrollments as $enrollment) {
            $enrollment->update([
                'status' => 'active',
                'next_step_at' => now(),
            ]);

            ProcessSequenceStep::dispatch($enrollment);
        }

        return $enrollments->count();
    }
}
