<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Services\CycleCountService;
use Illuminate\Console\Command;

class GenerateCycleCounts extends Command
{
    protected $signature = 'inventory:generate-cycle-counts';

    protected $description = 'Generate scheduled cycle count sessions for warehouses that are due, based on configured frequency.';

    public function handle(CycleCountService $service): int
    {
        $this->info('Checking warehouses for scheduled cycle counts...');

        $result = $service->generateScheduled();

        $this->info("Generated: {$result['generated']} new session(s)");
        $this->info("Skipped (not due): {$result['skipped']}");

        return self::SUCCESS;
    }
}
