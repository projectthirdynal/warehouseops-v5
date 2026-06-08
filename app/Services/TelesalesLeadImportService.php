<?php

namespace App\Services;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Enums\LeadStatus;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Enums\SalesStatus;
use App\Domain\Lead\Models\Lead;
use App\Events\LeadCreated;
use App\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class TelesalesLeadImportService
{
    public function __construct(
        private LeadAuditService $auditService
    ) {}

    /**
     * Import telesales leads from the old-sales CSV format.
     *
     * Expected columns (15 total):
     *  0: (empty/ID)
     *  1: name
     *  2: phone
     *  3: address
     *  4: province/region
     *  5: city
     *  6: barangay
     *  7-11: (empty)
     *  12: amount
     *  13: product_name
     *  14: order_status (Delivered, etc.)
     */
    public function import(UploadedFile $file, int $userId): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'])) {
            return $this->importXlsx($file, $userId);
        }

        return $this->importCsv($file, $userId);
    }

    private function importCsv(UploadedFile $file, int $userId): array
    {
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        // Skip header if first row looks like headers
        if ($this->isHeaderRow($rows[0] ?? [])) {
            array_shift($rows);
        }

        return $this->processRows($rows, $userId);
    }

    private function importXlsx(UploadedFile $file, int $userId): array
    {
        $rows = [];
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            // Determine column count dynamically (ISS-014)
            $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                $sheet->getHighestColumn()
            );

            for ($rowNum = 1; $rowNum <= $highestRow; $rowNum++) {
                $row = [];
                for ($col = 1; $col <= $highestColIndex; $col++) {
                    $row[] = $sheet->getCellByColumnAndRow($col, $rowNum)->getValue();
                }
                $rows[] = $row;
            }
        } catch (\Exception $e) {
            Log::error('Telesales XLSX import failed: ' . $e->getMessage());
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Failed to read XLSX: ' . $e->getMessage()]];
        }

        // Skip header if first row looks like headers
        if ($this->isHeaderRow($rows[0] ?? [])) {
            array_shift($rows);
        }

        return $this->processRows($rows, $userId);
    }

    private function processRows(array $rows, int $userId): array
    {

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($rows as $index => $row) {
            if (empty(array_filter($row))) {
                continue;
            }

            $result = $this->processRow($row, $userId);

            if (isset($result['error'])) {
                $stats['errors'][] = "Row " . ($index + 1) . ": {$result['error']}";
                $stats['skipped']++;
            } elseif ($result['action'] === 'created') {
                $stats['created']++;
            } elseif ($result['action'] === 'updated') {
                $stats['updated']++;
            }
        }

        Log::info("Telesales import complete", [
            'created' => $stats['created'],
            'updated' => $stats['updated'],
            'skipped' => $stats['skipped'],
            'errors' => count($stats['errors']),
        ]);

        return $stats;
    }

    private function isHeaderRow(array $row): bool
    {
        $first = strtolower(trim($row[0] ?? ''));
        return in_array($first, ['id', 'name', 'customer', 'phone', 'order']) ||
            str_contains($first, 'name');
    }

    private function processRow(array $row, int $userId): array
    {
        $name = trim($row[1] ?? '');
        $phoneRaw = $row[2] ?? '';
        $address = trim($row[3] ?? '');
        $province = $this->normalizeRegion($row[4] ?? '');
        $city = $this->normalizeRegion($row[5] ?? '');
        $barangay = $this->normalizeRegion($row[6] ?? '');
        $amount = $this->parseAmount($row[12] ?? null);
        $product = trim($row[13] ?? '');
        $orderStatus = trim($row[14] ?? '');

        if (empty($name) || empty($phoneRaw)) {
            return ['error' => 'Missing name or phone'];
        }

        $phone = $this->normalizePhone($phoneRaw);
        if (! $phone) {
            return ['error' => "Invalid phone: {$phoneRaw}"];
        }

        // Find or create customer
        $customer = Customer::firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $name,
                'address' => $address,
                'city' => $city,
                'province' => $province,
                'barangay' => $barangay,
            ]
        );

        $leadStatus = $this->mapOrderStatusToLeadStatus($orderStatus);

        // High quality score for existing customers (delivered orders)
        $qualityScore = 75;
        if (strtolower($orderStatus) === 'delivered') {
            $qualityScore = 85;
        }

        $existing = Lead::where('customer_id', $customer->id)
            ->whereIn('source', [LeadSource::TELESALES_IMPORT, LeadSource::XLSX_IMPORT])
            ->first();

        if ($existing) {
            $existing->update([
                'address' => $address ?: $existing->address,
                'city' => $city ?: $existing->city,
                'state' => $province ?: $existing->state,
                'barangay' => $barangay ?: $existing->barangay,
                'amount' => $amount ?? $existing->amount,
                'product_name' => $product ?: $existing->product_name,
                'quality_score' => max($existing->quality_score, $qualityScore),
                'last_scored_at' => now(),
                // Do NOT override COOLDOWN — supervisor may have intentionally set it
                'sales_status' => $this->mapOrderStatusToSalesStatus($orderStatus) ?? $existing->sales_status,
            ]);

            return ['action' => 'updated'];
        }

        $lead = Lead::create([
            'customer_id' => $customer->id,
            'name' => $name,
            'phone' => $phone,
            'address' => $address,
            'city' => $city,
            'state' => $province,
            'barangay' => $barangay,
            'product_name' => $product,
            'amount' => $amount,
            'notes' => "Order status: {$orderStatus}",
            'source' => LeadSource::TELESALES_IMPORT,
            'status' => $leadStatus ?? LeadStatus::NEW,
            'sales_status' => $this->mapOrderStatusToSalesStatus($orderStatus) ?? SalesStatus::NEW,
            'pool_status' => PoolStatus::AVAILABLE,
            'quality_score' => $qualityScore,
            'last_scored_at' => now(),
            'uploaded_by' => $userId,
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'LEAD_CREATED',
            metadata: [
                'source' => 'telesales_import',
                'uploaded_by' => $userId,
                'quality_score' => $qualityScore,
                'order_status' => $orderStatus,
            ]
        );

        LeadCreated::dispatch($lead);

        return ['action' => 'created'];
    }

    private function normalizePhone(mixed $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }

        if (is_float($raw) || (is_string($raw) && str_contains(strtolower((string)$raw), 'e'))) {
            $raw = (string) (int) round((float) $raw);
        } else {
            $raw = (string) $raw;
        }

        $digits = preg_replace('/\D/', '', $raw);

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0' . $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
            return '+63' . substr($digits, 1);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '639')) {
            return '+' . $digits;
        }

        if (strlen($digits) === 13 && str_starts_with($digits, '+639')) {
            return $digits;
        }

        return null;
    }

    private function normalizeRegion(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        return ucwords(strtolower(trim($value)));
    }

    private function parseAmount(mixed $value): ?float
    {
        if (empty($value) || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    private function mapOrderStatusToLeadStatus(string $orderStatus): ?LeadStatus
    {
        $normalized = strtolower(trim($orderStatus));

        return match ($normalized) {
            'delivered', 'completed', 'success', 'ordered' => LeadStatus::SALE,
            'cancelled', 'returned', 'failed', 'refused' => LeadStatus::CANCELLED,
            'pending', 'processing', 'confirmed' => LeadStatus::CALLBACK,
            default => LeadStatus::NEW,
        };
    }

    private function mapOrderStatusToSalesStatus(string $orderStatus): ?SalesStatus
    {
        $normalized = strtolower(trim($orderStatus));

        return match ($normalized) {
            'delivered', 'completed', 'success', 'ordered' => SalesStatus::WAYBILL_CREATED,
            'cancelled', 'returned', 'failed', 'refused' => SalesStatus::CANCELLED,
            'pending', 'processing', 'confirmed' => SalesStatus::QA_PENDING,
            default => null, // Keep existing / default
        };
    }
}
