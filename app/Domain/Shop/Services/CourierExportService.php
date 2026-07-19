<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Order\Models\Order;
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
    /**
     * @param Collection<int, Order> $orders
     */
    public function createBatch(Collection $orders, string $courierCode, ?int $userId, ?string $region = null): CourierExportBatch
    {
        $this->validateOrders($orders, $courierCode);

        return DB::transaction(function () use ($orders, $courierCode, $userId, $region) {
            $batch = CourierExportBatch::query()->create([
                'batch_number' => $this->batchNumber($courierCode),
                'courier_code' => $courierCode,
                'region' => $region,
                'status' => CourierExportBatch::STATUS_READY,
                'created_by' => $userId,
                'row_count' => $orders->count(),
                'exported_at' => now(),
                'metadata' => ['format' => strtoupper($courierCode) === 'JNT' ? 'JNT' : (strtoupper($courierCode) === 'FLASH' ? 'FLASH' : 'Generic CSV')],
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
        $headers = $this->headers($courierCode);

        return [
            'format'      => strtoupper($courierCode) === 'JNT' ? 'JNT' : (strtoupper($courierCode) === 'FLASH' ? 'FLASH' : 'Generic CSV'),
            'headers'     => $headers,
            'field_count' => count($headers),
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
        $headers = $this->headers($courierCode);
        $rows = [];

        foreach ($orders->take($limit) as $order) {
            [$productName, $quantity] = $this->orderLineSummary($order);
            $phone = $this->cleanPhone($order->receiver_phone);
            $sender = $this->senderInfo();
            $orderNumber = $order->order_number;

            $rows[] = match (strtoupper($courierCode)) {
                'JNT' => [
                    $orderNumber,
                    $order->receiver_name,
                    $phone,
                    $order->receiver_address,
                    $order->state,
                    $order->city,
                    $order->barangay,
                    $productName,
                    $quantity,
                    $order->cod_amount,
                    $order->cod_amount,
                    $order->notes,
                ],
                'FLASH' => [
                    $orderNumber,
                    $sender['name'],
                    $sender['phone'],
                    $sender['address'],
                    $sender['province'],
                    $sender['city'],
                    $order->receiver_name,
                    $phone,
                    $order->receiver_address,
                    $order->state,
                    $order->city,
                    $order->barangay,
                    $productName,
                    $quantity,
                    $order->cod_amount,
                    $order->notes,
                ],
                default => [
                    $orderNumber,
                    $order->receiver_name,
                    $phone,
                    $order->receiver_address,
                    $order->state,
                    $order->city,
                    $order->barangay,
                    $productName,
                    $quantity,
                    $order->cod_amount,
                    $order->notes,
                ],
            };
        }

        return [
            'headers'   => $headers,
            'rows'      => $rows,
            'row_count' => count($rows),
        ];
    }

    /**
     * @param Collection<int, Order> $orders
     * @return array{valid: bool, total: int, valid_count: int, invalid_count: int, orders: array<int, array{order_id: int, order_number: string, receiver_name: string, valid: bool, missing_fields: array<int, string>}>}
     */
    public function validateBatchItems(Collection $orders, string $courierCode): array
    {
        $required = $this->requiredFields($courierCode);
        $results = [];
        $validCount = 0;

        foreach ($orders as $order) {
            $missing = [];

            foreach ($required as $field => $label) {
                $value = match ($field) {
                    'receiver_name' => $order->receiver_name,
                    'phone_number' => $order->receiver_phone,
                    'complete_address' => $order->receiver_address,
                    'province' => $order->state,
                    'city' => $order->city,
                    'barangay' => $order->barangay,
                    'product_name' => $this->orderLineSummary($order)[0],
                    'quantity' => $this->orderLineSummary($order)[1],
                    'cod_amount' => $order->cod_amount,
                    default => null,
                };

                if (blank($value) || (is_numeric($value) && (float) $value <= 0 && $field !== 'cod_amount')) {
                    $missing[] = $label;
                }
            }

            $isValid = $missing === [];
            if ($isValid) {
                $validCount++;
            }

            $results[] = [
                'order_id'       => $order->id,
                'order_number'   => $order->order_number,
                'receiver_name'  => $order->receiver_name,
                'valid'          => $isValid,
                'missing_fields' => $missing,
            ];
        }

        return [
            'valid'         => $validCount === $orders->count(),
            'total'         => $orders->count(),
            'valid_count'   => $validCount,
            'invalid_count' => $orders->count() - $validCount,
            'required_fields' => array_values($required),
            'orders'         => $results,
        ];
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
        $orderNumber = $row->order?->order_number ?? $row->order_id;
        $phone = $this->cleanPhone($row->phone_number);
        $sender = $this->senderInfo();

        return match (strtoupper($courierCode)) {
            'JNT' => [
                $orderNumber,
                $row->receiver_name,
                $phone,
                $row->complete_address,
                $row->province,
                $row->city,
                $row->barangay,
                $row->product_name,
                $row->quantity,
                $row->cod_amount,
                $row->cod_amount,
                $row->remarks,
            ],
            'FLASH' => [
                $orderNumber,
                $sender['name'],
                $sender['phone'],
                $sender['address'],
                $sender['province'],
                $sender['city'],
                $row->receiver_name,
                $phone,
                $row->complete_address,
                $row->province,
                $row->city,
                $row->barangay,
                $row->product_name,
                $row->quantity,
                $row->cod_amount,
                $row->remarks,
            ],
            default => [
                $orderNumber,
                $row->receiver_name,
                $phone,
                $row->complete_address,
                $row->province,
                $row->city,
                $row->barangay,
                $row->product_name,
                $row->quantity,
                $row->cod_amount,
                $row->remarks,
            ],
        };
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

    public function rebuildBatch(CourierExportBatch $batch): CourierExportBatch
    {
        $failedRows = $batch->rows()->where('status', 'failed')->get();

        if ($failedRows->isEmpty()) {
            return $batch;
        }

        return DB::transaction(function () use ($batch, $failedRows) {
            $batch->forceFill([
                'status' => CourierExportBatch::STATUS_PROCESSING,
            ])->save();

            $rebuilt = 0;
            $stillFailed = 0;

            foreach ($failedRows as $row) {
                $order = $row->order;

                if (! $order) {
                    $row->forceFill([
                        'error_message' => 'Linked order no longer exists',
                    ])->save();
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

                    $rebuilt++;
                } catch (\Throwable $e) {
                    $row->forceFill([
                        'error_message' => $e->getMessage(),
                    ])->save();
                    $stillFailed++;
                }
            }

            $allRows = $batch->rows()->orderBy('row_number')->get();

            if ($stillFailed === 0) {
                $path = "exports/shop/{$batch->batch_number}.csv";
                $csvContent = $this->csv($allRows, $batch->courier_code);
                Storage::put($path, $csvContent);

                $batch->forceFill([
                    'status'            => CourierExportBatch::STATUS_READY,
                    'file_path'         => $path,
                    'file_size'         => strlen($csvContent),
                    'file_hash'         => hash('sha256', $csvContent),
                    'file_generated_at' => now(),
                    'row_count'         => $allRows->count(),
                ])->save();
            } else {
                $batch->forceFill([
                    'status' => CourierExportBatch::STATUS_READY,
                    'row_count' => $allRows->where('status', 'exported')->count(),
                ])->save();
            }

            return $batch->refresh();
        });
    }

    /**
     * @param Collection<int, Order> $orders
     */
    private function validateOrders(Collection $orders, string $courierCode): void
    {
        $required = $this->requiredFields($courierCode);
        $errors = [];

        foreach ($orders as $order) {
            $missing = [];

            foreach ($required as $field => $label) {
                $value = match ($field) {
                    'receiver_name' => $order->receiver_name,
                    'phone_number' => $order->receiver_phone,
                    'complete_address' => $order->receiver_address,
                    'province' => $order->state,
                    'city' => $order->city,
                    'barangay' => $order->barangay,
                    'product_name' => $this->orderLineSummary($order)[0],
                    'quantity' => $this->orderLineSummary($order)[1],
                    'cod_amount' => $order->cod_amount,
                    default => null,
                };

                if (blank($value) || (is_numeric($value) && (float) $value <= 0 && $field !== 'cod_amount')) {
                    $missing[] = $label;
                }
            }

            if ($missing !== []) {
                $errors[] = "{$order->order_number}: " . implode(', ', $missing);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'orders' => 'Courier export blocked. Missing required fields: ' . implode(' | ', $errors),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function requiredFields(string $courierCode): array
    {
        return match (strtoupper($courierCode)) {
            'JNT', 'FLASH' => [
                'receiver_name' => 'receiver name',
                'phone_number' => 'phone number',
                'complete_address' => 'complete address',
                'province' => 'province',
                'city' => 'city',
                'barangay' => 'barangay',
                'product_name' => 'product',
                'quantity' => 'quantity',
                'cod_amount' => 'COD amount',
            ],
            default => [
                'receiver_name' => 'receiver name',
                'phone_number' => 'phone number',
                'complete_address' => 'complete address',
                'product_name' => 'product',
                'quantity' => 'quantity',
                'cod_amount' => 'COD amount',
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public function headers(string $courierCode): array
    {
        return match (strtoupper($courierCode)) {
            'JNT' => [
                'Order Number',
                'Receiver Name',
                'Receiver Mobile',
                'Receiver Address',
                'Province',
                'City',
                'Barangay',
                'Item Name',
                'Quantity',
                'COD Amount',
                'Item Value',
                'Remark',
            ],
            'FLASH' => [
                'Order Number',
                'Sender Name',
                'Sender Mobile',
                'Sender Address',
                'Sender Province',
                'Sender City',
                'Consignee Name',
                'Consignee Mobile',
                'Consignee Address',
                'Province',
                'City',
                'Barangay',
                'Goods Name',
                'Quantity',
                'COD Amount',
                'Remark',
            ],
            default => [
                'Order Number',
                'Receiver Name',
                'Phone Number',
                'Complete Address',
                'Province',
                'City',
                'Barangay',
                'Product Name',
                'Quantity',
                'COD Amount',
                'Remarks',
            ],
        };
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
}
