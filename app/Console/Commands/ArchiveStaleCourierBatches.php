<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shop\Models\CourierExportBatch;
use Illuminate\Console\Command;

class ArchiveStaleCourierBatches extends Command
{
    protected $signature = 'shop:archive-stale-batches
                            {--days=14 : Archive eligible batches older than this many days}
                            {--dry-run : Report eligible batches without updating them}';

    protected $description = 'Archive ready or downloaded courier export batches after the retention period';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('The --days option must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $batches = CourierExportBatch::query()
            ->whereIn('status', [
                CourierExportBatch::STATUS_READY,
                CourierExportBatch::STATUS_DOWNLOADED,
            ])
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($batches->isEmpty()) {
            $this->info("No eligible batches older than {$days} days found.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Batch', 'Status', 'Created At'],
                $batches->map(fn (CourierExportBatch $batch) => [
                    $batch->batch_number,
                    $batch->status,
                    $batch->created_at?->toDateTimeString(),
                ])->all(),
            );
            $this->info("{$batches->count()} batch(es) would be archived.");

            return self::SUCCESS;
        }

        $archived = 0;

        foreach ($batches as $batch) {
            $batch->forceFill([
                'status' => CourierExportBatch::STATUS_ARCHIVED,
                'archived_at' => now(),
            ])->save();
            $archived++;
        }

        $this->info("Archived {$archived} batch(es) older than {$days} days.");

        return self::SUCCESS;
    }
}
