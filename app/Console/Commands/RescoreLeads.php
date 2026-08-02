<?php

namespace App\Console\Commands;

use App\Services\LeadScoringService;
use Illuminate\Console\Command;

/**
 * Leads & Distribution Engine — Phase 1 C1: Lead Scoring.
 *
 * Periodically refreshes quality_score for leads that have never been scored
 * or were last scored more than 7 days ago, so scores stay accurate as
 * customer delivery/conversion history accumulates over time.
 */
class RescoreLeads extends Command
{
    protected $signature = 'leads:rescore {--limit=200 : Maximum number of leads to rescore per run}';

    protected $description = 'Recompute quality_score for stale or never-scored leads';

    public function handle(LeadScoringService $scoringService): int
    {
        $limit = (int) $this->option('limit');

        $result = $scoringService->bulkRescore($limit);

        $this->info("Rescored {$result['rescored']} lead(s).");

        return self::SUCCESS;
    }
}
