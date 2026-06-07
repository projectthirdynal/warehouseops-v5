<?php

namespace App\Listeners;

use App\Domain\Lead\Models\Lead;
use App\Jobs\AutoDistributeLeads;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoDistributeOnLeadCreated
{
    /**
     * Trigger auto-distribution when a new lead is created.
     * Dispatches a small batch job immediately.
     */
    public function handle(object $event): void
    {
        // Only react to lead creation events that carry a Lead model
        if (! property_exists($event, 'lead') || ! $event->lead instanceof Lead) {
            return;
        }

        $lead = $event->lead;

        // Skip if lead is not in AVAILABLE pool status (e.g. manually assigned during import)
        if ($lead->pool_status?->value !== 'AVAILABLE') {
            return;
        }

        // Debounce: only dispatch one job per 30-second window to prevent
        // job storms during bulk imports (e.g. 100 leads = 100 jobs before)
        $lockKey = 'auto-distribute:debounce';
        if (! Cache::add($lockKey, true, now()->addSeconds(30))) {
            Log::info("AutoDistributeOnLeadCreated: Lead {$lead->id} created, debounced");
            return;
        }

        Log::info("AutoDistributeOnLeadCreated: Lead {$lead->id} created, dispatching batch job");

        // Dispatch a small batch job (size 5) to try assigning this lead immediately
        AutoDistributeLeads::dispatch(batchSize: 5);
    }
}
