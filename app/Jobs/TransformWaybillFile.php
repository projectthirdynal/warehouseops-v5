<?php

namespace App\Jobs;

use App\Models\ImportChunk;
use App\Models\Upload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Rap2hpoutre\FastExcel\FastExcel;

class TransformWaybillFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600; // 1 hour

    protected int $chunkSize = 10000; // 10k rows per chunk

    protected int $redisTTL = 7200; // 2 hours

    public function __construct(
        private int $uploadId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $upload = Upload::find($this->uploadId);

        if (! $upload || $upload->status === Upload::STATUS_CANCELLED) {
            return;
        }

        $upload->update(['status' => Upload::STATUS_TRANSFORMING]);

        try {
            $filePath = storage_path('app/'.$upload->file_path);
            $chunkNumber = 0;
            $chunk = [];
            $rowNumber = 0;

            (new FastExcel)->import($filePath, function ($row) use (&$chunk, &$chunkNumber, &$rowNumber, $upload) {
                $rowNumber++;

                // Transform row data
                $transformedRow = $this->transformRow($row, $upload->courier);
                $chunk[] = $transformedRow;

                // When chunk is full, store it
                if (count($chunk) >= $this->chunkSize) {
                    $this->storeChunk($upload->id, $chunkNumber, $chunk);

                    // Create chunk record
                    ImportChunk::create([
                        'upload_id' => $upload->id,
                        'chunk_number' => $chunkNumber,
                        'status' => ImportChunk::STATUS_PENDING,
                        'rows_count' => count($chunk),
                    ]);

                    $chunkNumber++;
                    $chunk = [];
                }

                // Check for cancellation every 5000 rows
                if ($rowNumber % 5000 === 0) {
                    if ($upload->fresh()->status === Upload::STATUS_CANCELLED) {
                        throw new \RuntimeException('Upload cancelled by user');
                    }
                }
            });

            // Store final chunk if any
            if (! empty($chunk)) {
                $this->storeChunk($upload->id, $chunkNumber, $chunk);
                ImportChunk::create([
                    'upload_id' => $upload->id,
                    'chunk_number' => $chunkNumber,
                    'status' => ImportChunk::STATUS_PENDING,
                    'rows_count' => count($chunk),
                ]);
                $chunkNumber++;
            }

            // Update upload status
            $upload->update([
                'status' => Upload::STATUS_READY_TO_IMPORT,
                'total_chunks' => $chunkNumber,
                'total_rows' => $rowNumber,
            ]);

            // Dispatch import jobs for each chunk
            for ($i = 0; $i < $chunkNumber; $i++) {
                ImportWaybillChunk::dispatch($upload->id, $i);
            }

        } catch (\Throwable $e) {
            $upload->update([
                'status' => Upload::STATUS_FAILED,
                'errors' => ['message' => $e->getMessage()],
                'completed_at' => now(),
            ]);
        }
    }

    protected function transformRow(array $row, string $courier): array
    {
        // Basic transformation - normalize column names
        $transformed = [];

        foreach ($row as $key => $value) {
            // Convert to snake_case for consistency
            $normalizedKey = strtolower(str_replace(' ', '_', trim($key)));
            $transformed[$normalizedKey] = $value;
        }

        return $transformed;
    }

    protected function storeChunk(int $uploadId, int $chunkNumber, array $data): void
    {
        $key = "upload:{$uploadId}:chunk:{$chunkNumber}";
        Redis::setex($key, $this->redisTTL, json_encode($data));
    }

    public function failed(\Throwable $e): void
    {
        $upload = Upload::find($this->uploadId);
        $upload?->update([
            'status' => Upload::STATUS_FAILED,
            'errors' => ['message' => $e->getMessage()],
            'completed_at' => now(),
        ]);
    }
}
