<?php

namespace App\Jobs;

use App\Imports\FlashWaybillFastImport;
use App\Imports\JntWaybillFastImport;
use App\Models\Upload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWaybillImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 7200; // 2 hours — handles 500k+ row files
    public bool $failOnTimeout = true;

    public function __construct(
        private int $uploadId,
        private string $courier,
        private string $filePath,
        private int $userId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        // Large XLSX files plus batch arrays need headroom; default php.ini may be 256M.
        ini_set('memory_limit', '1024M');

        $upload = Upload::find($this->uploadId);
        if (!$upload || $upload->status === 'cancelled') {
            return;
        }

        try {
            if ($this->courier === 'jnt') {
                $import = new JntWaybillFastImport($upload, $this->userId);
            } else {
                $import = new FlashWaybillFastImport($upload, $this->userId);
            }

            $import->import($this->filePath);

            if ($upload->fresh()->status === 'cancelled') {
                return;
            }

            $upload->update([
                'status' => 'completed',
                'errors' => $import->getErrors(),
            ]);

            GenerateLeadsFromUpload::dispatch($this->uploadId);

        } catch (\Throwable $e) {
            $upload->markAsFailed(['message' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $upload = Upload::find($this->uploadId);
        $upload?->markAsFailed(['message' => $e->getMessage()]);
    }
}
