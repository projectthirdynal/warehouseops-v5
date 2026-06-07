<?php

namespace App\Console;

use App\Domain\Courier\Jobs\SyncTrackingStatusJob;
use App\Jobs\AutoDistributeLeads;
use App\Jobs\DetectFraudPatterns;
use App\Jobs\ProcessCooldownLeads;
use App\Models\Upload;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new ProcessCooldownLeads)->everyFifteenMinutes();
        $schedule->job(new DetectFraudPatterns)->everyThirtyMinutes();
        $schedule->job(new SyncTrackingStatusJob)->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // Auto-distribute leads from queue every minute
        $schedule->job(new AutoDistributeLeads(batchSize: 20))
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();

        // Auto-fail orphaned imports: stuck in 'processing' with 0 rows for >15 min
        $schedule->call(function () {
            Upload::where('type', 'waybill')
                ->where('status', 'processing')
                ->where('processed_rows', 0)
                ->where('created_at', '<', now()->subMinutes(15))
                ->each(fn ($u) => $u->markAsFailed(['message' => 'Job did not start — retried automatically. Please retry.']));
        })->everyFifteenMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
