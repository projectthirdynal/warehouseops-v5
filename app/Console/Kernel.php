<?php

namespace App\Console;

use App\Jobs\DetectFraudPatterns;
use App\Jobs\ProcessCooldownLeads;
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
