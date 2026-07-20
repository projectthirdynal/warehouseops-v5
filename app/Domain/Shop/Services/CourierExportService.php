<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Order\Models\Order;
use App\Domain\Shop\CourierCsv\CourierCsvSchemaRegistry;
use App\Domain\Shop\CourierCsv\CourierCsvValidator;
use App\Domain\Shop\Models\BatchItemErrorLog;
use App\Domain\Shop\Models\CourierExportBatch;
use App\Domain\Shop\Models\CourierExportRow;
use App\Models\User;
use App\Notifications\CourierExportBatchReadyNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CourierExportService
{
    public function __construct(
        private readonly CourierCsvSchemaRegistry $schemas,
        private readonly CourierCsvValidator $validator,
    ) {}

    /**
     * @param Collection<int, Order> $orders
     */
    public function createBatch(Collection $orders, string $courierCode, ?int $userId, ?string $region = null): CourierExportBatch
    {
        $this->validateOrders($orders, $courierCode);

        return DB::transaction(function () use ($orders, $courierCode, $userId, $region) {
            $schema = $this->schemas->resolve($courierCode);

            $batch = CourierExportBatch::query()->create([
                'batch_number' => $this->batchNumber($courierCode),
                'courier_code' => $courierCode,
                'region' => $region,
                'status' => CourierExportBatch::STATUS_READY,
                'created_by' => $userId,
                'row_count' => $orders->count(),
                'exported_at' => now(),
                'metadata' => ['format' => $schema->name],
            ]);

            $rows = $orders->values()->map(function (Order $order, int $index) use ($batch) {
                [$productName, $quantity] = $this->orderLineSummary($order);

                $row = CourierExportRow::query()->create([
                    'courier_export_batch_id' => $batch->id,
                    'order_id' => $order->id,
                    'row_number' => $index + 1,
                    'status' => 'exported',
                    'receiver_name' => $order->receiver_name,
                    'phone_number' => $order->receiver_phone,
                    'complete_address' => $order->receiver_address,
                    'province' => $order->state,
                    'city' => $order->city,
                    'barangay' => $order->barangay,
                    'product_name' => $productName,
                    'cod_amount' => $order->cod_amount,
                    'quantity' => $quantity,
                    'remarks' => $order->notes,
                    'exported_at' => now(),
                ]);

                $order->forceFill([
                    'export_status' => 'exported',
                    'encoded_at' => $order->encoded_at ?? now(),
                ])->save();

                return $row;
            });

            $path = "exports/shop/{$batch->batch_number}.csv";
            $csvContent = $this->csv($rows, $courierCode);
            Storage::put($path, $csvContent);
            $batch->forceFill([
                'file_path'         => $path,
                'file_size'         => strlen($csvContent),
                'file_hash'         => hash('sha256', $csvContent),
                'file_generated_at' => now(),
            ])->save();

            if ($userId !== null) {
                $user = User::query()->find($userId);
                if ($user !== null) {
                    Notification::send($user, new CourierExportBatchReadyNotification($batch));
                }
            }

            return $batch;
        });
    }

    /**
     * @param Collection<int, Order> $orders
     * @param array<int, string> $courierCodes
     * @return Collection<int, CourierExportBatch>
     */
    public function createBatchesForCouriers(Collection $orders, array $courierCodes, ?int $userId): Collection
    {
        return collect($courierCodes)->flatMap(function (string $courierCode) use ($orders, $userId) {
            $this->validateOrders($orders, $courierCode);

            return [$this->createBatch($orders, $courierCode, $userId)];
        })->values();
    }

    /**
     * @param Collection<int, Order> $orders
     * @return Collection<int, CourierExportBatch>
     */
    public function createBatchesByRegion(Collection $orders, string $courierCode, ?int $userId): Collection
    {
        $grouped = $orders->groupBy(fn (Order $order) => $order->state ?? 'Unknown');

        return $grouped->map(fn (Collection $regionOrders, string $region) =>
            $this->createBatch($regionOrders, $courierCode, $userId, $region)
        )->values();
    }

    /**
     * @return array{format: string, headers: array<int, string>, field_count: int}
     */
    public function csvFormatInfo(string $courierCode): array
    {
        $schema = $this->schemas->resolve($courierCode);

        return [
            'format'      => $schema->name,
            'headers'     => $schema->headers(),
            'field_count' => $schema->columnCount(),
        ];
    }

    /**
     * Generate a CSV preview from orders without creating a batch.
     *
     * @param Collection<int, Order> $orders
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>, row_count: int}
     */
    public function previewCsv(Collection $orders, string $courierCode, int $limit = 10): array
    {
        $schema = $this->schemas->resolve($courierCode);
        $rows = [];

        foreach ($orders->take($limit) as $order) {
            $rows[] = $this->orderToCsvRow($order, $schema);
        }

        return [
            'headers'   => $schema->headers(),
            'rows'      => $rows,
            'row_count' => count($rows),
        ];
    }

    /**
     * @param Collection<int, Order> $orders
     * @return array{valid: bool, total: int, valid_count: int, invalid_count: int, schema: string, courier_code: string, required_columns: array<int, string>, column_count: int, orders: array<int, array{order_id: int, order_number: string, receiver_name: string, valid: bool, missing_columns: array<int, string>, missing_fields: array<int, array{column: string, field: string, value: mixed}>}>}
     */
    public function validateBatchItems(Collection $orders, string $courierCode): array
    {
        return $this->validator->validateOrders($orders, $courierCode);
    }

    /**
     * @param Collection<int, CourierExportRow> $rows
     */
    public function csv(Collection $rows, string $courierCode): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $this->headers($courierCode));

        foreach ($rows as $row) {
            fputcsv($handle, $this->rowValues($row, $courierCode));
        }

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    /**
     * Map a CourierExportRow to courier-specific CSV values.
     */
    public function rowValues(CourierExportRow $row, string $courierCode): array
    {
        $schema = $this->schemas->resolve($courierCode);

        return array_map(
            fn (\App\Domain\Shop\CourierCsv\CourierCsvColumn $col) => $this->resolveRowField($row, $col->field),
            $schema->columns,
        );
    }

    /**
     * @return array<string, string>
     */
    private function senderInfo(): array
    {
        return [
            'name' => (string) config('services.shop.sender_name'),
            'phone' => $this->cleanPhone((string) config('services.shop.sender_phone')),
            'address' => (string) config('services.shop.sender_address'),
            'province' => (string) config('services.shop.sender_province'),
            'city' => (string) config('services.shop.sender_city'),
        ];
    }

    private function cleanPhone(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0' . substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return $digits;
        }

        return $digits;
    }

    private function batchNumber(string $courierCode): string
    {
        return sprintf('SHOP-%s-%s-%04d', strtoupper($courierCode), now()->format('Ymd'), CourierExportBatch::whereDate('created_at', today())->count() + 1);
    }

    private function logRowError(CourierExportBatch $batch, CourierExportRow $row, string $message, string $errorType = 'export', string $severity = 'error'): void
    {
        BatchItemErrorLog::query()->create([
            'courier_export_batch_id' => $batch->id,
            'courier_export_row_id'   => $row->id,
            'order_id'                => $row->order_id,
            'error_type'              => $errorType,
            'error_message'           => $message,
            'severity'                => $severity,
        ]);
    }

    public function rebuildBatch(CourierExportBatch $batch): CourierExportBatch
    {
        $failedRows = $batch->rows()->where('status', 'failed')->get();

        if ($failedRows->isEmpty()) {
            return $batch;
        }

        return DB::transaction(function () use ($batch, $failedRows) {
            $batch->transitionTo(
                CourierExportBatch::STATUS_PROCESSING,
                'Rebuild started',
            );

            $rebuilt = 0;
            $stillFailed = 0;

            foreach ($failedRows as $row) {
                $order = $row->order;

                if (! $order) {
                    $row->forceFill([
                        'error_message' => 'Linked order no longer exists',
                    ])->save();
                    $this->logRowError($batch, $row, 'Linked order no longer exists', 'rebuild', 'error');
                    $stillFailed++;
                    continue;
                }

                try {
                    [$productName, $quantity] = $this->orderLineSummary($order);

                    $row->forceFill([
                        'status' => 'exported',
                        'receiver_name' => $order->receiver_name,
                        'phone_number' => $order->receiver_phone,
                        'complete_address' => $order->receiver_address,
                        'province' => $order->state,
                        'city' => $order->city,
                        'barangay' => $order->barangay,
                        'product_name' => $productName,
                        'cod_amount' => $order->cod_amount,
                        'quantity' => $quantity,
                        'remarks' => $order->notes,
                        'error_message' => null,
                        'exported_at' => now(),
                    ])->save();

                    BatchItemErrorLog::query()
                        ->where('courier_export_row_id', $row->id)
                        ->whereNull('resolved_at')
                        ->update([
                            'resolution' => 'Fixed during rebuild',
                            'resolved_at' => now(),
                            'resolved_by' => auth()->id(),
                        ]);

                    $rebuilt++;
                } catch (\Throwable $e) {
                    $row->forceFill([
                        'error_message' => $e->getMessage(),
                    ])->save();
                    $this->logRowError($batch, $row, $e->getMessage(), 'rebuild', 'error');
                    $stillFailed++;
                }
            }

            $allRows = $batch->rows()->orderBy('row_number')->get();

            if ($stillFailed === 0) {
                $path = "exports/shop/{$batch->batch_number}.csv";
                $csvContent = $this->csv($allRows, $batch->courier_code);
                Storage::put($path, $csvContent);

                $batch->forceFill([
                    'file_path'         => $path,
                    'file_size'         => strlen($csvContent),
                    'file_hash'         => hash('sha256', $csvContent),
                    'file_generated_at' => now(),
                    'row_count'         => $allRows->count(),
                ])->save();
                $batch->transitionTo(
                    CourierExportBatch::STATUS_READY,
                    'Rebuild completed — all rows fixed',
                );
            } else {
                $batch->forceFill([
                    'row_count' => $allRows->where('status', 'exported')->count(),
                ])->save();
                $batch->transitionTo(
                    CourierExportBatch::STATUS_READY,
                    "Rebuild completed — {$stillFailed} rows still failing",
                );
            }

            return $batch->refresh();
        });
    }

    public function rebuildBatchFull(CourierExportBatch $batch): CourierExportBatch
    {
        return DB::transaction(function () use ($batch) {
            $batch->transitionTo(
                CourierExportBatch::STATUS_PROCESSING,
                'Full rebuild started — refreshing all rows from order data',
            );

            $rows = $batch->rows()->orderBy('row_number')->get();
            $rebuilt = 0;
            $stillFailed = 0;

            foreach ($rows as $row) {
                $order = $row->order;

                if (! $order) {
                    $row->forceFill([
                        'status' => 'failed',
                        'error_message' => 'Linked order no longer exists',
                    ])->save();
                    $this->logRowError($batch, $row, 'Linked order no longer exists', 'rebuild-full', 'error');
                    $stillFailed++;
                    continue;
                }

                try {
                    [$productName, $quantity] = $this->orderLineSummary($order);

                    $row->forceFill([
                        'status' => 'exported',
                        'receiver_name' => $order->receiver_name,
                        'phone_number' => $order->receiver_phone,
                        'complete_address' => $order->receiver_address,
                        'province' => $order->state,
                        'city' => $order->city,
                        'barangay' => $order->barangay,
                        'product_name' => $productName,
                        'cod_amount' => $order->cod_amount,
                        'quantity' => $quantity,
                        'remarks' => $order->notes,
                        'error_message' => null,
                        'exported_at' => now(),
                    ])->save();

                    BatchItemErrorLog::query()
                        ->where('courier_export_row_id', $row->id)
                        ->whereNull('resolved_at')
                        ->update([
                            'resolution' => 'Fixed during full rebuild',
                            'resolved_at' => now(),
                            'resolved_by' => auth()->id(),
                        ]);

                    $rebuilt++;
                } catch (\Throwable $e) {
                    $row->forceFill([
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ])->save();
                    $this->logRowError($batch, $row, $e->getMessage(), 'rebuild-full', 'error');
                    $stillFailed++;
                }
            }

            $allRows = $batch->rows()->orderBy('row_number')->get();

            if ($stillFailed === 0) {
                $path = "exports/shop/{$batch->batch_number}.csv";
                $csvContent = $this->csv($allRows, $batch->courier_code);
                Storage::put($path, $csvContent);

                $batch->forceFill([
                    'file_path'         => $path,
                    'file_size'         => strlen($csvContent),
                    'file_hash'         => hash('sha256', $csvContent),
                    'file_generated_at' => now(),
                    'row_count'         => $allRows->count(),
                ])->save();
                $batch->transitionTo(
                    CourierExportBatch::STATUS_READY,
                    "Full rebuild completed — {$rebuilt} rows refreshed, all fixed",
                );
            } else {
                $batch->forceFill([
                    'row_count' => $allRows->where('status', 'exported')->count(),
                ])->save();
                $batch->transitionTo(
                    CourierExportBatch::STATUS_READY,
                    "Full rebuild completed — {$rebuilt} rows refreshed, {$stillFailed} still failing",
                );
            }

            return $batch->refresh();
        });
    }

    /**
     * @param Collection<int, Order> $orders
     */
    private function validateOrders(Collection $orders, string $courierCode): void
    {
        $errors = $this->validator->getExportBlockingErrors($orders, $courierCode);

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'orders' => 'Courier export blocked. Missing required columns: ' . implode(' | ', $errors),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function requiredFields(string $courierCode): array
    {
        return $this->schemas->resolve($courierCode)->requiredFields();
    }

    /**
     * @return array<int, string>
     */
    public function headers(string $courierCode): array
    {
        return $this->schemas->resolve($courierCode)->headers();
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    private function orderLineSummary(Order $order): array
    {
        $items = $order->relationLoaded('shopItems') ? $order->shopItems : collect();

        if ($items->isNotEmpty()) {
            return [
                $items->map(fn ($item) => "{$item->product_name} x{$item->quantity}")->implode(', '),
                (int) $items->sum('quantity'),
            ];
        }

        return [$order->product?->name, (int) $order->quantity];
    }

    /**
     * Resolve a field value from an Order for CSV preview.
     *
     * @return mixed
     */
    private function resolveOrderField(Order $order, string $field)
    {
        $sender = $this->senderInfo();

        return match ($field) {
            'order_number'      => $order->order_number,
            'receiver_name'     => $order->receiver_name,
            'phone_number'      => $this->cleanPhone($order->receiver_phone),
            'complete_address'  => $order->receiver_address,
            'province'          => $order->state,
            'city'              => $order->city,
            'barangay'          => $order->barangay,
            'product_name'      => $this->orderLineSummary($order)[0],
            'quantity'          => $this->orderLineSummary($order)[1],
            'cod_amount'        => $order->cod_amount,
            'remarks'           => $order->notes,
            'sender_name'       => $sender['name'],
            'sender_phone'      => $sender['phone'],
            'sender_address'    => $sender['address'],
            'sender_province'   => $sender['province'],
            'sender_city'       => $sender['city'],
            default             => '',
        };
    }

    /**
     * Resolve a field value from a CourierExportRow for CSV generation.
     *
     * @return mixed
     */
    private function resolveRowField(CourierExportRow $row, string $field)
    {
        $sender = $this->senderInfo();

        return match ($field) {
            'order_number'      => $row->order?->order_number ?? $row->order_id,
            'receiver_name'     => $row->receiver_name,
            'phone_number'      => $this->cleanPhone($row->phone_number),
            'complete_address'  => $row->complete_address,
            'province'          => $row->province,
            'city'              => $row->city,
            'barangay'          => $row->barangay,
            'product_name'      => $row->product_name,
            'quantity'          => $row->quantity,
            'cod_amount'        => $row->cod_amount,
            'remarks'           => $row->remarks,
            'sender_name'       => $sender['name'],
            'sender_phone'      => $sender['phone'],
            'sender_address'    => $sender['address'],
            'sender_province'   => $sender['province'],
            'sender_city'       => $sender['city'],
            default             => '',
        };
    }

    /**
     * Convert an Order to a CSV row using the schema.
     *
     * @return array<int, mixed>
     */
    private function orderToCsvRow(Order $order, \App\Domain\Shop\CourierCsv\CourierCsvSchema $schema): array
    {
        return array_map(
            fn (\App\Domain\Shop\CourierCsv\CourierCsvColumn $col) => $this->resolveOrderField($order, $col->field),
            $schema->columns,
        );
    }
}
