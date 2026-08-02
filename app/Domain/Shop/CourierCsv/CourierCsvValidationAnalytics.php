<?php

declare(strict_types=1);

namespace App\Domain\Shop\CourierCsv;

use App\Domain\Shop\Models\CourierCsvValidationErrorLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Collects and aggregates historical courier CSV validation errors.
 */
final class CourierCsvValidationAnalytics
{
    /**
     * Persist validation errors from a validation result.
     *
     * @param  array<string, mixed>  $validationResult
     * @param  array<int, int>|null  $orderIds
     */
    public function record(
        array $validationResult,
        string $courierCode,
        ?int $batchId = null,
        ?string $source = null,
    ): void {
        $orders = $validationResult['orders'] ?? $validationResult['rows'] ?? [];

        foreach ($orders as $row) {
            if ($row['valid'] ?? false) {
                continue;
            }

            $orderId = $row['order_id'] ?? null;
            $rowId = $row['row_id'] ?? null;

            $messages = [];

            foreach ($row['missing_fields'] ?? [] as $field) {
                $messages[] = $field['error'] ?? ($field['column'] ?? $field['field'] ?? 'unknown').' is invalid';
            }

            foreach ($row['address_errors'] ?? [] as $error) {
                $messages[] = 'Address: '.$error;
            }

            foreach ($row['weight_errors'] ?? [] as $error) {
                $messages[] = 'Weight: '.$error;
            }

            if ($messages === []) {
                $messages[] = 'Validation failed';
            }

            foreach ($messages as $message) {
                $errorType = $this->detectErrorType($message);

                CourierCsvValidationErrorLog::create([
                    'courier_export_batch_id' => $batchId,
                    'courier_export_row_id' => $rowId,
                    'order_id' => $orderId,
                    'courier_code' => strtoupper($courierCode),
                    'error_type' => $errorType,
                    'error_message' => $message,
                    'context' => [
                        'missing_columns' => $row['missing_columns'] ?? [],
                    ],
                    'source' => $source,
                ]);
            }
        }
    }

    /**
     * Summarize validation errors by courier, error type, and day.
     *
     * @return array<string, mixed>
     */
    public function summary(
        ?string $courierCode = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        string $groupBy = 'error_type',
    ): array {
        $query = CourierCsvValidationErrorLog::query();

        if ($courierCode !== null) {
            $query->where('courier_code', strtoupper($courierCode));
        }

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        $total = (int) $query->count();

        if ($groupBy === 'courier') {
            $byGroup = $query->clone()
                ->select('courier_code', DB::raw('COUNT(*) as count'))
                ->groupBy('courier_code')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($row) => ['courier_code' => $row->courier_code, 'count' => (int) $row->count])
                ->toArray();
        } else {
            $byGroup = $query->clone()
                ->select('error_type', DB::raw('COUNT(*) as count'))
                ->groupBy('error_type')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($row) => ['error_type' => $row->error_type, 'count' => (int) $row->count])
                ->toArray();
        }

        $byDay = $query->clone()
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as count'))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['day' => $row->day, 'count' => (int) $row->count])
            ->toArray();

        return [
            'total' => $total,
            'group_by' => $groupBy,
            'by_group' => $byGroup,
            'by_day' => $byDay,
            'from' => $from?->toDateTimeString(),
            'to' => $to?->toDateTimeString(),
        ];
    }

    /**
     * @return Collection<int, CourierCsvValidationErrorLog>
     */
    public function recent(
        ?string $courierCode = null,
        ?string $errorType = null,
        ?Carbon $from = null,
        int $limit = 50,
    ): Collection {
        $query = CourierCsvValidationErrorLog::query()
            ->with(['order:id,order_number', 'batch:id,batch_number'])
            ->orderByDesc('created_at');

        if ($courierCode !== null) {
            $query->where('courier_code', strtoupper($courierCode));
        }

        if ($errorType !== null) {
            $query->where('error_type', $errorType);
        }

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        return $query->limit($limit)->get();
    }

    private function detectErrorType(string $message): string
    {
        if (stripos($message, 'phone') !== false) {
            return 'phone';
        }

        if (stripos($message, 'cod') !== false || stripos($message, 'amount') !== false) {
            return 'cod_amount';
        }

        if (stripos($message, 'address') !== false || stripos($message, 'province') !== false || stripos($message, 'city') !== false || stripos($message, 'barangay') !== false) {
            return 'address';
        }

        if (stripos($message, 'weight') !== false || stripos($message, 'dimension') !== false) {
            return 'weight';
        }

        if (stripos($message, 'required') !== false || stripos($message, 'missing') !== false) {
            return 'missing_field';
        }

        return 'unknown';
    }
}
