<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Services\WaybillFileValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValidateWaybillFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes

    public function __construct(
        private int $uploadId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $upload = Upload::find($this->uploadId);
        
        if (!$upload || $upload->status === Upload::STATUS_CANCELLED) {
            return;
        }

        $upload->update(['status' => Upload::STATUS_VALIDATING]);

        try {
            $validator = new WaybillFileValidator($upload);
            $result = $validator->validate();

            if (!$result->isValid()) {
                $upload->update([
                    'status' => Upload::STATUS_VALIDATION_FAILED,
                    'errors' => $result->toArray(),
                    'completed_at' => now(),
                ]);
                return;
            }

            // Store validation metadata
            $resultData = $result->toArray();
            $upload->update([
                'status' => Upload::STATUS_VALIDATED,
                'total_rows' => $resultData['row_count'],
                'metadata' => [
                    'columns' => $resultData['columns'],
                    'sample_rows' => $resultData['sample_rows'],
                    'warnings' => $resultData['warnings'],
                    'duplicate_count' => $resultData['duplicate_count'],
                ],
            ]);

            // Auto-start transformation if enabled
            if ($upload->auto_import) {
                TransformWaybillFile::dispatch($upload->id);
            }

        } catch (\Throwable $e) {
            $upload->update([
                'status' => Upload::STATUS_VALIDATION_FAILED,
                'errors' => ['message' => $e->getMessage()],
                'completed_at' => now(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $upload = Upload::find($this->uploadId);
        $upload?->update([
            'status' => Upload::STATUS_VALIDATION_FAILED,
            'errors' => ['message' => $e->getMessage()],
            'completed_at' => now(),
        ]);
    }
}
