<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Services\DepreciationAutomationService;
use Illuminate\Console\Command;

class PostDepreciation extends Command
{
    protected $signature = 'inventory:post-depreciation';

    protected $description = 'Generate and post monthly depreciation journal entries for active assets';

    public function handle(DepreciationAutomationService $service): int
    {
        $this->info('Processing depreciation journal entries...');

        $result = $service->postDueEntries();

        $this->info("Generated: {$result['generated']} new entries");
        $this->info("Posted: {$result['posted']} entries totaling ".number_format($result['total_amount'], 2));

        return self::SUCCESS;
    }
}
