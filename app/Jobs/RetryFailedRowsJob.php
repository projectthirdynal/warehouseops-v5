<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Imports\FlashWaybillFastImport;
use App\Imports\JntWaybillFastImport;
use App\Models\Upload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Rap2hpoutre\FastExcel\FastExcel;

class RetryFailedRowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;
    public bool $failOnTimeout = true;

    public function __construct(
        private int $uploadId,
        private int $userId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        ini_set('memory_limit', '1024M');

        $upload = Upload::find($this->uploadId);
        if (!$upload) {
            return;
        }

        $errors = $upload->errors ?? [];
        if (empty($errors)) {
            return;
        }

        $failedRowNumbers = array_filter(
            array_map(fn ($e) => is_numeric($e['row'] ?? null) ? (int) $e['row'] : null, $errors),
            fn ($r) => $r !== null,
        );

        if (empty($failedRowNumbers)) {
            return;
        }

        $filePath = Storage::disk('local')->path('uploads/waybills/' . $upload->filename);
        if (!file_exists($filePath)) {
            $upload->update(['retry_status' => 'failed_no_file']);
            return;
        }

        $upload->update([
            'retry_status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $failedRows = $this->extractFailedRows($filePath, $failedRowNumbers);

            if (empty($failedRows)) {
                $upload->update(['retry_status' => 'completed_no_rows']);
                return;
            }

            $import = $upload->courier === 'jnt'
                ? new JntWaybillFastImport($upload, $this->userId)
                : new FlashWaybillFastImport($upload, $this->userId);

            $retryErrors = [];
            $retrySuccess = 0;
            $retryFailed = 0;

            foreach ($failedRows as $rowNumber => $rowData) {
                try {
                    $now = now()->toDateTimeString();
                    $mapped = $this->callMapRow($import, $rowData, $now);
                    if ($mapped) {
                        $import->bulkUpsert([$mapped]);
                        $retrySuccess++;
                    }
                } catch (\Throwable $e) {
                    $retryFailed++;
                    if (count($retryErrors) < 100) {
                        $retryErrors[] = ['row' => $rowNumber, 'error' => $e->getMessage()];
                    }
                }
            }

            $remainingErrors = array_filter($errors, function ($e) use ($failedRowNumbers) {
                $r = $e['row'] ?? null;
                return !is_numeric($r) || !in_array((int) $r, array_keys($failedRows));
            });

            $upload->update([
                'retry_status' => $retryFailed > 0 ? 'completed_with_errors' : 'completed',
                'retry_count' => $upload->retry_count + 1,
                'error_rows' => $retryFailed + count($remainingErrors),
                'success_rows' => $upload->success_rows + $retrySuccess,
                'errors' => array_merge($retryErrors, $remainingErrors),
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $upload->update([
                'retry_status' => 'failed',
                'completed_at' => now(),
            ]);
        }
    }

    protected function extractFailedRows(string $filePath, array $failedRowNumbers): array
    {
        $failedRows = [];
        $rowNumber = 0;
        $failedSet = array_flip($failedRowNumbers);

        (new FastExcel)->import($filePath, function ($row) use (&$failedRows, &$rowNumber, $failedSet) {
            $rowNumber++;
            if (isset($failedSet[$rowNumber])) {
                $failedRows[$rowNumber] = $row;
            }
        });

        return $failedRows;
    }

    protected function callMapRow(object $import, array $row, string $now): ?array
    {
        $reflection = new \ReflectionMethod($import, 'mapRow');
        $reflection->setAccessible(true);
        return $reflection->invoke($import, $row, $now);
    }

    public function failed(\Throwable $e): void
    {
        $upload = Upload::find($this->uploadId);
        $upload?->update([
            'retry_status' => 'failed',
            'completed_at' => now(),
        ]);
    }
}
