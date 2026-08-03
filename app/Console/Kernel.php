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
        $schedule->job(new ProcessCooldownLeads)->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->job(new DetectFraudPatterns)->everyThirtyMinutes();
        $schedule->job(new SyncTrackingStatusJob)->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // Auto-distribute leads from queue every minute
        $schedule->job(new AutoDistributeLeads(batchSize: 20))
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('inventory:recompute-stock-status')->daily()->withoutOverlapping();
        $schedule->command('inventory:release-expired-reservations')->hourly()->withoutOverlapping();
        $schedule->command('invoices:mark-overdue')->dailyAt('06:00')->withoutOverlapping();
        $schedule->command('shop:process-scheduled-messages')->everyMinute()->withoutOverlapping();
        $schedule->command('shop:archive-stale-batches')->dailyAt('02:45')->withoutOverlapping();
        $schedule->command('shop:cleanup-old-batches')->dailyAt('03:00')->withoutOverlapping();
        $schedule->command('shop:check-idle-agents')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('shop:enforce-shift-hours')->everyMinute()->withoutOverlapping()->onOneServer();
        $schedule->command('shop:auto-resolve-inactive')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('shop:escalate-sla-breached')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('shop:apply-status-rules')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('shop:send-order-followups')->dailyAt('09:00')->withoutOverlapping()->onOneServer();
        $schedule->command('shop:archive-stale-conversations')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
        $schedule->command('shop:check-gamification')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
        $schedule->command('sales-dashboard:generate-scheduled-reports')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('quality:train')->dailyAt('04:15')->withoutOverlapping()->onOneServer();
        $schedule->command('leads:rescore --limit=500')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('leads:check-pool-capacity')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('predictive:retrain')->dailyAt('04:30')->withoutOverlapping()->onOneServer();
        $schedule->command('meta:validate-tokens')->dailyAt('05:00')->withoutOverlapping()->onOneServer();

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
