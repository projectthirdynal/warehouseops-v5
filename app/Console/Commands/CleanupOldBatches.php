<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shop\Models\CourierExportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOldBatches extends Command
{
    protected $signature = 'shop:cleanup-old-batches {--days=30 : Delete batches archived more than N days ago}';
    protected $description = 'Delete archived courier export batches older than the specified number of days';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $cutoff = now()->subDays($days);

        $batches = CourierExportBatch::query()
            ->where('status', CourierExportBatch::STATUS_ARCHIVED)
            ->where('archived_at', '<', $cutoff)
            ->get();

        if ($batches->isEmpty()) {
            $this->info("No archived batches older than {$days} days found.");

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($batches as $batch) {
            if ($batch->file_path && Storage::disk('local')->exists($batch->file_path)) {
                Storage::disk('local')->delete($batch->file_path);
            }

            $batch->rows()->delete();
            $batch->delete();
            $deleted++;
        }

        $this->info("Deleted {$deleted} archived batch(es) older than {$days} days.");

        return self::SUCCESS;
    }
}
