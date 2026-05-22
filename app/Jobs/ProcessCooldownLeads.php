<?php

namespace App\Jobs;

use App\Services\LeadRecyclingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCooldownLeads implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(LeadRecyclingService $recyclingService): void
    {
        $processed = $recyclingService->processExpiredCooldowns();
        Log::info("ProcessCooldownLeads: Processed {$processed} leads");
    }
}
