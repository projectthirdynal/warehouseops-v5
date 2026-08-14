<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\PoolCapacityAlertNotification;
use App\Services\LeadPoolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckPoolCapacity extends Command
{
    protected $signature = 'leads:check-pool-capacity';

    protected $description = 'Check the lead pool for low-availability or overstocked-unassigned conditions and notify supervisors.';

    private const COOLDOWN_HOURS = 2;

    public function handle(LeadPoolService $poolService): int
    {
        $alerts = $poolService->checkCapacityAlerts();

        if (empty($alerts)) {
            $this->info('Pool capacity is within normal thresholds. No alerts.');

            return Command::SUCCESS;
        }

        $supervisors = User::query()
            ->whereIn('role', ['supervisor', 'admin', 'superadmin'])
            ->where('is_active', true)
            ->get();

        $notifiedCount = 0;

        foreach ($alerts as $alert) {
            $cacheKey = sprintf(
                'pool_capacity_alert:%s:%s',
                $alert['level'],
                $alert['source'] ?? 'overall'
            );

            // Skip if this same alert was already sent within the cooldown window
            if (Cache::has($cacheKey)) {
                continue;
            }

            Cache::put($cacheKey, true, now()->addHours(self::COOLDOWN_HOURS));

            if ($supervisors->isNotEmpty()) {
                Notification::send(
                    $supervisors,
                    new PoolCapacityAlertNotification(
                        $alert['level'],
                        $alert['count'],
                        $alert['threshold'],
                        $alert['source'],
                    )
                );
            }

            $notifiedCount++;
            $scope = $alert['source'] ?? 'overall pool';
            $this->info("Alert [{$alert['level']}] for {$scope}: {$alert['count']} (threshold {$alert['threshold']}).");
        }

        if ($notifiedCount > 0) {
            Log::info("Pool capacity check: {$notifiedCount} new alert(s) sent to supervisors.");
        }

        $this->info('Total alerts detected: '.count($alerts).', new notifications sent: '.$notifiedCount);

        return Command::SUCCESS;
    }
}
