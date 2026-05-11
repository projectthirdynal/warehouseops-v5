<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Order\Models\Order;
use App\Domain\Shop\Models\CourierExportBatch;
use App\Domain\Shop\Models\CourierExportRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CourierExportService
{
    /**
     * @param Collection<int, Order> $orders
     */
    public function createBatch(Collection $orders, string $courierCode, ?int $userId): CourierExportBatch
    {
        $this->validateOrders($orders, $courierCode);

        return DB::transaction(function () use ($orders, $courierCode, $userId) {
            $batch = CourierExportBatch::query()->create([
                'batch_number' => $this->batchNumber($courierCode),
                'courier_code' => $courierCode,
                'status' => 'exported',
                'created_by' => $userId,
                'row_count' => $orders->count(),
                'exported_at' => now(),
                'metadata' => ['format' => 'generic_csv'],
            ]);

            $rows = $orders->values()->map(function (Order $order, int $index) use ($batch) {
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
                    'product_name' => $order->product?->name,
                    'cod_amount' => $order->cod_amount,
                    'quantity' => $order->quantity,
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
            Storage::put($path, $this->csv($rows, $courierCode));
            $batch->forceFill(['file_path' => $path])->save();

            return $batch;
        });
    }

    /**
     * @param Collection<int, CourierExportRow> $rows
     */
    private function csv(Collection $rows, string $courierCode): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $this->headers($courierCode));

        foreach ($rows as $row) {
            $values = [
                $row->receiver_name,
                $row->phone_number,
                $row->complete_address,
                $row->province,
                $row->city,
                $row->barangay,
                $row->product_name,
                $row->quantity,
                $row->cod_amount,
                $row->remarks,
            ];

            fputcsv($handle, $values);
        }

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    private function batchNumber(string $courierCode): string
    {
        return sprintf('SHOP-%s-%s-%04d', strtoupper($courierCode), now()->format('Ymd'), CourierExportBatch::whereDate('created_at', today())->count() + 1);
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
                    'product_name' => $order->product?->name,
                    'quantity' => $order->quantity,
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
    private function headers(string $courierCode): array
    {
        return match (strtoupper($courierCode)) {
            'JNT' => [
                'Receiver Name',
                'Receiver Mobile',
                'Receiver Address',
                'Province',
                'City',
                'Barangay',
                'Item Name',
                'Quantity',
                'COD Amount',
                'Remark',
            ],
            'FLASH' => [
                'consignee_name',
                'consignee_mobile',
                'consignee_address',
                'province',
                'city',
                'barangay',
                'goods_name',
                'quantity',
                'cod_amount',
                'remark',
            ],
            default => [
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
}
