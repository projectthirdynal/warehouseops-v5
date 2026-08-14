<?php

namespace App\Console\Commands;

use App\Services\LeadScoringService;
use Illuminate\Console\Command;

/**
 * Leads & Distribution Engine — Phase 4 L2: Quality ML Model.
 *
 * Retrains the lead-source conversion-rate model from historical LeadCycle
 * outcomes so `LeadScoringService` auto-scores new/rescored leads using
 * actual observed conversion data instead of the static SOURCE_SCORES table.
 */
class TrainQualityModel extends Command
{
    protected $signature = 'quality:train';

    protected $description = 'Retrain the lead quality (source conversion) model from historical LeadCycle data';

    public function handle(LeadScoringService $scoringService): int
    {
        $this->info('Training lead quality model...');

        $result = $scoringService->train();

        $this->info("Sample size: {$result['sample_size']} closed cycles");
        $this->info("Positive (ORDERED) count: {$result['positive_count']}");
        $this->info("Sources trained: {$result['sources_trained']}");

        return self::SUCCESS;
    }
}
