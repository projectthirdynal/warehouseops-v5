<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Modules\Finance\Models\AgentCommission;
use Modules\Finance\Models\CommissionRun;
use Modules\Finance\Services\CommissionService;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateCommissionRuns extends Command
{
    protected $signature = 'finance:generate-commission-runs
                            {--period= : Override period type (DAILY, WEEKLY, MONTHLY)}
                            {--backfill : Backfill missed commissions before generating runs}';

    protected $description = 'Generate commission runs from pending commissions based on automation settings';

    public function handle(CommissionService $service): int
    {
        $autoGenerate = (bool) SiteSetting::get('commission_auto_generate', '0');

        if (! $autoGenerate && ! $this->option('period')) {
            $this->info('Commission auto-generation is disabled. Use --period to force.');

            return self::SUCCESS;
        }

        if ($this->option('backfill')) {
            $this->info('Backfilling missed commissions...');
            $result = $service->backfillMissedCommissions();
            $this->info("  Created: {$result['created']}, Skipped: {$result['skipped']}");
        }

        $periodType = $this->option('period')
            ?: SiteSetting::get('commission_run_frequency', CommissionRun::PERIOD_MONTHLY);

        $validPeriods = [CommissionRun::PERIOD_DAILY, CommissionRun::PERIOD_WEEKLY, CommissionRun::PERIOD_MONTHLY];

        if (! in_array($periodType, $validPeriods)) {
            $this->error("Invalid period type: {$periodType}");

            return self::FAILURE;
        }

        $unassignedCount = AgentCommission::where('status', 'PENDING')
            ->whereNull('commission_run_id')
            ->count();

        if ($unassignedCount === 0) {
            $this->info('No pending commissions to include in a run.');

            return self::SUCCESS;
        }

        $this->info("Generating {$periodType} commission run ({$unassignedCount} pending commissions)...");

        $run = $service->createRun($periodType);

        $this->info("  Run created: {$run->name}");
        $this->info("  Commissions: {$run->commission_count}");
        $this->info("  Total amount: {$run->total_amount}");

        $autoApproveThreshold = (float) SiteSetting::get('commission_auto_approve_threshold', '0');
        $requireApproval = (bool) SiteSetting::get('commission_require_approval', '1');

        if (! $requireApproval || ($autoApproveThreshold > 0 && (float) $run->total_amount <= $autoApproveThreshold)) {
            $this->info('  Auto-approving run (below threshold or approval not required)...');

            $systemUser = User::where('role', 'superadmin')->first();
            if ($systemUser) {
                $service->approveRun($run, $systemUser->id);
                $this->info('  Run approved automatically.');
            }
        } else {
            $this->info('  Run requires manual approval.');
        }

        return self::SUCCESS;
    }
}
