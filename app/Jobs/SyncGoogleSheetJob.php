<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Courier\Jobs\SyncTrackingStatusJob;
use App\Domain\Waybill\Models\GoogleSheetConfig;
use App\Domain\Waybill\Services\GoogleSheetSyncService;
use App\Models\Upload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncGoogleSheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(
        private int $uploadId,
        private int $configId,
        private int $userId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        ini_set('memory_limit', '1024M');

        $upload = Upload::find($this->uploadId);
        if (! $upload || $upload->status === 'cancelled') {
            return;
        }

        $config = GoogleSheetConfig::find($this->configId);
        if (! $config) {
            $upload->markAsFailed(['message' => 'Sheet config not found.']);

            return;
        }

        $upload->update([
            'status' => Upload::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        try {
            $service = app(GoogleSheetSyncService::class);
            $result = $service->syncConfig($config, $upload, $this->userId);

            $errorCount = $result['errors'] ?? 0;

            $upload->update([
                'status' => $errorCount > 0
                    ? Upload::STATUS_COMPLETED_WITH_ERRORS
                    : Upload::STATUS_COMPLETED,
                'errors' => $errorCount > 0 ? ($result['error_details'] ?? []) : null,
                'completed_at' => now(),
            ]);

            // Generate leads from newly imported waybills (same as file import)
            GenerateLeadsFromUpload::dispatch($this->uploadId);

            // Trigger tracking status sync
            SyncTrackingStatusJob::dispatch(strtoupper($config->courier))
                ->onQueue('default')
                ->delay(now()->addSeconds(30));

            Log::info("Google Sheet sync completed for upload {$this->uploadId}: {$result['rows_read']} rows read, {$result['imported']} imported, {$errorCount} errors");

        } catch (\Throwable $e) {
            Log::error("Google Sheet sync failed for upload {$this->uploadId}: ".$e->getMessage());
            $upload->markAsFailed(['message' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $upload = Upload::find($this->uploadId);
        $upload?->markAsFailed(['message' => $e->getMessage()]);
    }
}
