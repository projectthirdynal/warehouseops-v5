<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadCycle;
use App\Services\LeadRecyclingService;
use Illuminate\Console\Command;

class ProcessRecycling extends Command
{
    protected $signature = 'leads:process-recycling
                            {--cooldown-only : Only process expired cooldowns}
                            {--callbacks-only : Only process expired callbacks}
                            {--dry-run : Show counts without making changes}';

    protected $description = 'Process lead recycling: expired cooldowns and expired callbacks';

    public function handle(LeadRecyclingService $service): int
    {
        $cooldownOnly = $this->option('cooldown-only');
        $callbacksOnly = $this->option('callbacks-only');
        $dryRun = $this->option('dry-run');

        $cooldownProcessed = 0;
        $callbackProcessed = 0;

        if (! $callbacksOnly) {
            if ($dryRun) {
                $cooldownProcessed = Lead::cooldownExpired()->count();
                $this->info("Dry run — {$cooldownProcessed} leads with expired cooldowns would be processed.");
            } else {
                $cooldownProcessed = $service->processExpiredCooldowns();
                $this->info("Processed {$cooldownProcessed} leads from expired cooldowns.");
            }
        }

        if (! $cooldownOnly) {
            if ($dryRun) {
                $callbackProcessed = LeadCycle::where('status', 'ACTIVE')
                    ->whereNotNull('callback_at')
                    ->where('callback_at', '<', now()->subHours(24))
                    ->where('call_count', '>', 0)
                    ->count();
                $this->info("Dry run — {$callbackProcessed} expired callbacks would be processed.");
            } else {
                $callbackProcessed = $service->processExpiredCallbacks();
                $this->info("Processed {$callbackProcessed} expired callbacks.");
            }
        }

        $total = $cooldownProcessed + $callbackProcessed;
        $this->info("Total processed: {$total}");

        return self::SUCCESS;
    }
}
