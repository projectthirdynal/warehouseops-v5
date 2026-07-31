<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shop\Services\GamificationService;
use Illuminate\Console\Command;

class CheckGamification extends Command
{
    protected $signature = 'shop:check-gamification';
    protected $description = 'Check and award badges, update milestones, and track streaks for all active agents';

    public function handle(GamificationService $service): int
    {
        $this->info('Running gamification checks...');

        $result = $service->bulkCheckAndAward();

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
