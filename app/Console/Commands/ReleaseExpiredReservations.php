<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\StockService;
use Illuminate\Console\Command;

class ReleaseExpiredReservations extends Command
{
    protected $signature = 'inventory:release-expired-reservations';

    protected $description = 'Release stock reservations that have passed their expires_at timestamp';

    public function handle(StockService $stockService): int
    {
        $expired = StockReservation::where('status', 'ACTIVE')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired reservations found.');

            return self::SUCCESS;
        }

        $released = 0;
        $failed = 0;

        foreach ($expired as $reservation) {
            try {
                $stockService->release($reservation, 'expired');
                $released++;
            } catch (\Throwable $e) {
                $this->warn("Failed to release reservation #{$reservation->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Released {$released} expired reservation(s). Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
