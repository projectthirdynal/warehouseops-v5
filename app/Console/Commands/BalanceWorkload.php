<?php

namespace App\Console\Commands;

use App\Services\WorkloadBalancingService;
use Illuminate\Console\Command;

class BalanceWorkload extends Command
{
    protected $signature = 'shop:balance-workload';
    protected $description = 'Detect overloaded agents, redistribute excess leads, and auto-pause if still overloaded';

    public function handle(WorkloadBalancingService $service): int
    {
        $this->info('Running workload balancing cycle...');

        $result = $service->runBalancingCycle();

        $this->info("Overloaded agents detected: {$result['overloaded_detected']}");
        $this->info("Agents auto-paused: {$result['agents_paused']}");
        $this->info("Leads redistributed: {$result['leads_redistributed']}");

        foreach ($result['details'] as $detail) {
            $action = $detail['action'] ?? 'unknown';
            $name = $detail['agent_name'] ?? "Agent #{$detail['agent_id']}";
            $this->line("  - {$name}: {$action}");

            if (! empty($detail['errors'])) {
                foreach ($detail['errors'] as $error) {
                    $this->error("    ERROR: {$error}");
                }
            }
        }

        if ($result['overloaded_detected'] === 0) {
            $this->info('All agents are within capacity. No action needed.');
        }

        return self::SUCCESS;
    }
}
