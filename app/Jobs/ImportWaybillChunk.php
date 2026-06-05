<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Models\ImportChunk;
use App\Models\Waybill;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class ImportWaybillChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes per chunk
    protected int $batchSize;

    public function __construct(
        private int $uploadId,
        private int $chunkNumber,
    ) {
        $this->onQueue('imports');
        
        // Calculate batch size dynamically
        $columnCount = 35; // Approximate column count
        $this->batchSize = (int) floor(65000 / $columnCount);
    }

    public function handle(): void
    {
        $upload = Upload::find($this->uploadId);
        $chunk = ImportChunk::where('upload_id', $this->uploadId)
            ->where('chunk_number', $this->chunkNumber)
            ->first();

        if (!$upload || !$chunk || $upload->status === Upload::STATUS_CANCELLED) {
            return;
        }

        $chunk->markAsProcessing();

        try {
            // Retrieve chunk data from Redis
            $key = "upload:{$this->uploadId}:chunk:{$this->chunkNumber}";
            $data = json_decode(Redis::get($key), true);

            if (!$data) {
                throw new \Exception("Chunk data not found in Redis");
            }

            // Process data in batches
            $batches = array_chunk($data, $this->batchSize);
            $totalInserted = 0;
            $totalUpdated = 0;
            $totalErrors = 0;
            $errors = [];

            foreach ($batches as $batch) {
                try {
                    $counts = $this->processBatch($batch, $upload);
                    $totalInserted += $counts['inserted'];
                    $totalUpdated += $counts['updated'];
                } catch (\Throwable $e) {
                    $totalErrors++;
                    if (count($errors) < 100) {
                        $errors[] = ['error' => $e->getMessage()];
                    }
                }
            }

            // Update chunk status
            $chunk->markAsCompleted([
                'inserted' => $totalInserted,
                'updated' => $totalUpdated,
                'errors' => $totalErrors,
            ]);

            if ($totalErrors > 0) {
                $chunk->update(['errors' => $errors]);
            }

            // Update upload progress atomically
            DB::table('uploads')->where('id', $this->uploadId)->update([
                'processed_chunks' => DB::raw('processed_chunks + 1'),
                'inserted_rows' => DB::raw("inserted_rows + {$totalInserted}"),
                'updated_rows' => DB::raw("updated_rows + {$totalUpdated}"),
                'error_rows' => DB::raw("error_rows + {$totalErrors}"),
            ]);

            // Clean up Redis data
            Redis::del($key);

            // Check if all chunks are complete
            $this->checkCompletion();

        } catch (\Throwable $e) {
            $chunk->markAsFailed(['message' => $e->getMessage()]);
            
            DB::table('uploads')->where('id', $this->uploadId)->update([
                'processed_chunks' => DB::raw('processed_chunks + 1'),
            ]);
        }
    }

    protected function processBatch(array $batch, Upload $upload): array
    {
        $now = now()->toDateTimeString();
        $waybillData = [];

        foreach ($batch as $row) {
            try {
                $data = $this->mapRowToWaybill($row, $upload, $now);
                $waybillData[] = $data;
            } catch (\Throwable $e) {
                // Skip invalid rows
                continue;
            }
        }

        if (empty($waybillData)) {
            return ['inserted' => 0, 'updated' => 0];
        }

        // Get existing waybill numbers
        $waybillNumbers = array_column($waybillData, 'waybill_number');
        $existing = DB::table('waybills')
            ->whereIn('waybill_number', $waybillNumbers)
            ->pluck('waybill_number')
            ->flip()
            ->all();

        // Perform upsert
        Waybill::upsert(
            $waybillData,
            ['waybill_number'],
            ['status', 'signed_at', 'delivered_at', 'returned_at', 'updated_at']
        );

        // Calculate inserted vs updated
        $inserted = count(array_filter($waybillNumbers, fn($n) => !isset($existing[$n])));
        $updated = count($waybillData) - $inserted;

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    protected function mapRowToWaybill(array $row, Upload $upload, string $now): array
    {
        // Map based on courier type
        if ($upload->courier === 'jnt') {
            return $this->mapJntRow($row, $upload, $now);
        } else {
            return $this->mapFlashRow($row, $upload, $now);
        }
    }

    protected function mapJntRow(array $row, Upload $upload, string $now): array
    {
        return [
            'waybill_number' => $row['waybill_number'] ?? throw new \Exception('Missing waybill number'),
            'status' => $this->mapStatus('JNT', $row['order_status'] ?? 'PENDING'),
            'receiver_name' => $row['receiver'] ?? 'Unknown',
            'receiver_phone' => $row['receiver_cellphone'] ?? '',
            'receiver_address' => $row['address'] ?? '',
            'state' => $row['province'] ?? null,
            'city' => $row['city'] ?? null,
            'barangay' => $row['barangay'] ?? null,
            'cod_amount' => $this->parseNumeric($row['cod'] ?? 0),
            'shipping_cost' => $this->parseNumeric($row['total_shipping_cost'] ?? 0),
            'courier_provider' => 'J&T',
            'upload_id' => $upload->id,
            'uploaded_by' => $upload->uploaded_by,
            'signed_at' => $this->parseDateTime($row['signingtime'] ?? null),
            'delivered_at' => null,
            'returned_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    protected function mapFlashRow(array $row, Upload $upload, string $now): array
    {
        return [
            'waybill_number' => $row['tracking_no'] ?? throw new \Exception('Missing tracking number'),
            'status' => $this->mapStatus('FLASH', $row['status'] ?? 'PENDING'),
            'receiver_name' => $row['consignee_name'] ?? 'Unknown',
            'receiver_phone' => $row['consignee_phone'] ?? '',
            'receiver_address' => $row['address'] ?? '',
            'state' => $row['province'] ?? null,
            'city' => $row['city'] ?? null,
            'barangay' => $row['barangay'] ?? null,
            'cod_amount' => $this->parseNumeric($row['cod_amount'] ?? 0),
            'shipping_cost' => $this->parseNumeric($row['shipping_fee'] ?? 0),
            'courier_provider' => 'Flash',
            'upload_id' => $upload->id,
            'uploaded_by' => $upload->uploaded_by,
            'signed_at' => null,
            'delivered_at' => null,
            'returned_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    protected function mapStatus(string $courier, string $status): string
    {
        $mapper = app(\App\Domain\Courier\Services\StatusMapper::class);
        return $mapper->resolve($courier, $status)->value;
    }

    protected function parseNumeric($value): float
    {
        if (empty($value)) return 0;
        return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }

    protected function parseDateTime($value): ?string
    {
        if (empty($value)) return null;
        
        try {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            $ts = strtotime((string) $value);
            return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function checkCompletion(): void
    {
        $upload = Upload::find($this->uploadId);
        
        if ($upload->processed_chunks >= $upload->total_chunks) {
            $hasErrors = ImportChunk::where('upload_id', $this->uploadId)
                ->where('error_count', '>', 0)
                ->exists();

            $upload->update([
                'status' => $hasErrors ? Upload::STATUS_COMPLETED_WITH_ERRORS : Upload::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            // Trigger post-import jobs
            GenerateLeadsFromUpload::dispatch($this->uploadId);
        }
    }

    public function failed(\Throwable $e): void
    {
        $chunk = ImportChunk::where('upload_id', $this->uploadId)
            ->where('chunk_number', $this->chunkNumber)
            ->first();
            
        $chunk?->markAsFailed(['message' => $e->getMessage()]);
    }
}
