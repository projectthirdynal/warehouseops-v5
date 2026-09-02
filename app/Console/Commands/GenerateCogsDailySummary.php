<?php

namespace App\Console\Commands;

use Modules\Finance\Services\CogsDashboardService;
use Illuminate\Console\Command;

class GenerateCogsDailySummary extends Command
{
    protected $signature = 'cogs:generate-daily-summary
                            {--date= : Specific date (Y-m-d). Defaults to yesterday.}
                            {--days= : Generate for last N days (including today).}';

    protected $description = 'Generate COGS daily summaries and variance alerts from cogs_entries';

    public function handle(CogsDashboardService $service): int
    {
        $days = (int) $this->option('days');
        $date = $this->option('date');

        if ($days > 0) {
            $this->info("Generating COGS summaries for last {$days} days...");
            $total = 0;
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = now()->subDays($i)->toDateString();
                $count = $service->generateDailySummary($d);
                $total += $count;
                $this->line("  {$d}: {$count} summaries");
            }
            $this->info("Done. {$total} total summaries generated.");
        } else {
            $targetDate = $date ?? now()->subDay()->toDateString();
            $this->info("Generating COGS summary for {$targetDate}...");
            $count = $service->generateDailySummary($targetDate);
            $this->info("Done. {$count} summaries generated.");
        }

        return self::SUCCESS;
    }
}
