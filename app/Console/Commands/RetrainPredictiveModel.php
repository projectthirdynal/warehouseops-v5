<?php

namespace App\Console\Commands;

use App\Services\PredictiveAssignmentService;
use Illuminate\Console\Command;

class RetrainPredictiveModel extends Command
{
    protected $signature = 'predictive:retrain';

    protected $description = 'Retrain the predictive assignment model from historical LeadCycle data';

    public function handle(PredictiveAssignmentService $service): int
    {
        $this->info('Retraining predictive model...');

        $result = $service->retrain();

        $this->info("Trained {$result['agents_trained']} agents");
        $this->info("Total cycles analyzed: {$result['total_cycles']}");
        $this->info("Total sales: {$result['total_sales']}");

        return self::SUCCESS;
    }
}
