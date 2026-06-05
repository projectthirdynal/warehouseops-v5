<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Models\Waybill;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class ProcessStreamingChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        private int $uploadId,
        private int $chunkNumber,
        private int $rowsCount,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $upload = Upload::find($this->uploadId);
        
        if (!$upload || $upload->status === Upload::STATUS_CANCELLED) {
            return;
        }

        try {
            // Retrieve chunk data from Redis
            $key = "upload:{$this->uploadId}:chunk:{$this->chunkNumber}";
            $data = json_decode(Redis::get($key), true);

            if (!$data) {
                throw new \Exception("Chunk data not found");
            }

            // Process data immediately
            $counts = $this->processChunk($data, $upload);

            // Update upload progress atomically
            DB::table('uploads')->where('id', $this->uploadId)->update([
                'processed_rows' => DB::raw("processed_rows + {$this->rowsCount}"),
                'inserted_rows' => DB::raw("inserted_rows + {$counts['inserted']}"),
                'updated_rows' => DB::raw("updated_rows + {$counts['updated']}"),
                'error_rows' => DB::raw("error_rows + {$counts['errors']}"),
                'processed_chunks' => DB::raw('processed_chunks + 1'),
            ]);

            // Clean up Redis data immediately
            Redis::del($key);

            // Check if this was the last chunk
            $this->checkCompletion();

        } catch (\Throwable $e) {
            DB::table('uploads')->where('id', $this->uploadId)->update([
                'error_rows' => DB::raw("error_rows + {$this->rowsCount}"),
                'processed_chunks' => DB::raw('processed_chunks + 1'),
            ]);

            throw $e;
        }
    }

    protected function processChunk(array $data, Upload $upload): array
    {
        $now = now()->toDateTimeString();
        $waybillData = [];
        $errors = 0;

        foreach ($data as $row) {
            try {
                $mapped = $this->mapRow($row, $upload, $now);
                $waybillData[] = $mapped;
            } catch (\Throwable $e) {
                $errors++;
                continue;
            }
        }

        if (empty($waybillData)) {
            return ['inserted' => 0, 'updated' => 0, 'errors' => $errors];
        }

        // Get existing waybill numbers for insert/update tracking
        $waybillNumbers = array_column($waybillData, 'waybill_number');
        $existing = DB::table('waybills')
            ->whereIn('waybill_number', $waybillNumbers)
            ->pluck('waybill_number')
            ->flip()
            ->all();

        // Batch upsert
        Waybill::upsert(
            $waybillData,
            ['waybill_number'],
            ['status', 'signed_at', 'delivered_at', 'returned_at', 'updated_at']
        );

        $inserted = count(array_filter($waybillNumbers, fn($n) => !isset($existing[$n])));
        $updated = count($waybillData) - $inserted;

        return ['inserted' => $inserted, 'updated' => $updated, 'errors' => $errors];
    }

    protected function mapRow(array $row, Upload $upload, string $now): array
    {
        if ($upload->courier === 'jnt') {
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
                'created_at' => $now,
                'updated_at' => $now,
            ];
        } else {
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
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
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
        $lastChunk = Redis::get("upload:{$this->uploadId}:last_chunk");

        if ($lastChunk !== null && $upload->processed_chunks > (int)$lastChunk) {
            $upload->update([
                'status' => $upload->error_rows > 0 
                    ? Upload::STATUS_COMPLETED_WITH_ERRORS 
                    : Upload::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            // Clean up
            Redis::del("upload:{$this->uploadId}:last_chunk");

            // Trigger post-import jobs
            GenerateLeadsFromUpload::dispatch($this->uploadId);
        }
    }
}
