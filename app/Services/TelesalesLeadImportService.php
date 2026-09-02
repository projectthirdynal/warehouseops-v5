<?php

namespace App\Services;

use Modules\Leads\Enums\LeadSource;
use Modules\Leads\Enums\LeadStatus;
use Modules\Leads\Enums\PoolStatus;
use Modules\Leads\Enums\SalesStatus;
use Modules\Leads\Models\Lead;
use App\Events\LeadCreated;
use App\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TelesalesLeadImportService
{
    /**
     * Legacy fixed column positions (0-indexed), used as a fallback when no
     * mapping is supplied by the caller.
     */
    private const DEFAULT_MAPPING = [
        'name' => 1,
        'phone' => 2,
        'address' => 3,
        'province' => 4,
        'city' => 5,
        'barangay' => 6,
        'amount' => 12,
        'product_name' => 13,
        'order_status' => 14,
    ];

    /**
     * Keywords used to auto-guess a field mapping from header labels.
     */
    private const FIELD_KEYWORDS = [
        'name' => ['customer name', 'full name', 'name', 'customer'],
        'phone' => ['phone', 'mobile', 'contact', 'cell', 'number'],
        'address' => ['address'],
        'province' => ['province', 'region'],
        'city' => ['city', 'municipality'],
        'barangay' => ['barangay', 'brgy'],
        'amount' => ['amount', 'total', 'price'],
        'product_name' => ['product', 'item'],
        'order_status' => ['order status', 'status'],
    ];

    public function __construct(
        private LeadAuditService $auditService,
        private LeadScoringService $scoringService
    ) {}

    /**
     * Detect columns in the uploaded file and suggest a field mapping based
     * on header labels (falls back to legacy positions when no header row
     * or no keyword match is found).
     *
     * @return array{columns: array<int, array{index: int, label: string, samples: array<int, string>}>, suggested_mapping: array<string, int>, has_header: bool}
     */
    public function detectColumns(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allRows = in_array($extension, ['xlsx', 'xls'])
            ? $this->parseXlsxRawRows($file, skipHeader: false)
            : $this->parseCsvRawRows($file, skipHeader: false);

        if (empty($allRows)) {
            return ['columns' => [], 'suggested_mapping' => self::DEFAULT_MAPPING, 'has_header' => false];
        }

        $firstRow = $allRows[0];
        $hasHeader = $this->isHeaderRow($firstRow);
        $headerLabels = $hasHeader ? $firstRow : [];
        $sampleRows = array_slice($allRows, $hasHeader ? 1 : 0, 3);

        $columnCount = max(array_map('count', $allRows));
        $columns = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $label = $hasHeader && isset($headerLabels[$i]) && trim((string) $headerLabels[$i]) !== ''
                ? trim((string) $headerLabels[$i])
                : 'Column '.($i + 1);

            $samples = array_values(array_unique(array_filter(
                array_map(fn ($r) => isset($r[$i]) ? trim((string) $r[$i]) : '', $sampleRows)
            )));

            $columns[] = ['index' => $i, 'label' => $label, 'samples' => $samples];
        }

        return [
            'columns' => $columns,
            'suggested_mapping' => $this->guessMapping($columns, $hasHeader),
            'has_header' => $hasHeader,
        ];
    }

    /**
     * @param  array<int, array{index: int, label: string, samples: array<int, string>}>  $columns
     * @return array<string, int>
     */
    private function guessMapping(array $columns, bool $hasHeader): array
    {
        $mapping = [];

        foreach (self::FIELD_KEYWORDS as $field => $keywords) {
            foreach ($columns as $col) {
                $label = strtolower($col['label']);
                foreach ($keywords as $keyword) {
                    if (str_contains($label, $keyword)) {
                        $mapping[$field] = $col['index'];

                        continue 3;
                    }
                }
            }
        }

        // Without a header row we cannot match by keyword — fall back to the
        // legacy fixed positions for any field that could not be guessed.
        // With a header row, leave unmatched fields unmapped so the user
        // must explicitly confirm them (avoids silently mismapping columns).
        if (! $hasHeader) {
            foreach (self::DEFAULT_MAPPING as $field => $index) {
                if (! isset($mapping[$field]) && isset($columns[$index])) {
                    $mapping[$field] = $index;
                }
            }
        }

        return $mapping;
    }

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
    public function import(UploadedFile $file, int $userId, array $mapping = []): array
    {
        $mapping = array_merge(self::DEFAULT_MAPPING, $mapping);
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'])) {
            return $this->importXlsx($file, $userId, $mapping);
        }

        return $this->importCsv($file, $userId, $mapping);
    }

    /**
     * Preview import: parse file, detect duplicates, return validation results without writing.
     *
     * @return array{summary: array, rows: array}
     */
    public function preview(UploadedFile $file, array $mapping = []): array
    {
        $mapping = array_merge(self::DEFAULT_MAPPING, $mapping);
        $extension = strtolower($file->getClientOriginalExtension());
        $rawRows = in_array($extension, ['xlsx', 'xls'])
            ? $this->parseXlsxRawRows($file)
            : $this->parseCsvRawRows($file);

        $previewRows = [];
        $seenPhones = [];
        $summary = ['total' => 0, 'new' => 0, 'duplicate_db' => 0, 'duplicate_file' => 0, 'errors' => 0];

        $nameCol = $mapping['name'];
        $phoneCol = $mapping['phone'];
        $cityCol = $mapping['city'];

        // Pre-fetch existing phones from DB in one query
        $phonesToCheck = [];
        foreach ($rawRows as $row) {
            $phone = $this->normalizePhone($row[$phoneCol] ?? null);
            if ($phone) {
                $phonesToCheck[] = $phone;
            }
        }
        $existingPhones = Lead::whereIn('phone', array_unique($phonesToCheck))
            ->whereNotIn('pool_status', [PoolStatus::EXHAUSTED])
            ->pluck('phone')
            ->flip();

        foreach ($rawRows as $index => $row) {
            $summary['total']++;
            $rowNum = $index + 1;
            $name = trim((string) ($row[$nameCol] ?? ''));
            $phoneRaw = $row[$phoneCol] ?? null;
            $phone = $this->normalizePhone($phoneRaw);

            if (empty($name)) {
                $summary['errors']++;
                $previewRows[] = [
                    'row' => $rowNum,
                    'name' => '',
                    'phone' => $phoneRaw ? (string) $phoneRaw : '',
                    'city' => trim((string) ($row[$cityCol] ?? '')),
                    'status' => 'error',
                    'error' => 'Name is required',
                ];

                continue;
            }

            if (! $phone) {
                $summary['errors']++;
                $previewRows[] = [
                    'row' => $rowNum,
                    'name' => $name,
                    'phone' => $phoneRaw ? (string) $phoneRaw : '',
                    'city' => trim((string) ($row[$cityCol] ?? '')),
                    'status' => 'error',
                    'error' => 'Invalid phone number',
                ];

                continue;
            }

            if (isset($seenPhones[$phone])) {
                $summary['duplicate_file']++;
                $previewRows[] = [
                    'row' => $rowNum,
                    'name' => $name,
                    'phone' => $phone,
                    'city' => trim((string) ($row[$cityCol] ?? '')),
                    'status' => 'duplicate_file',
                    'error' => null,
                ];

                continue;
            }

            $seenPhones[$phone] = true;

            if ($existingPhones->has($phone)) {
                $summary['duplicate_db']++;
                $previewRows[] = [
                    'row' => $rowNum,
                    'name' => $name,
                    'phone' => $phone,
                    'city' => trim((string) ($row[$cityCol] ?? '')),
                    'status' => 'duplicate_db',
                    'error' => null,
                ];

                continue;
            }

            $summary['new']++;
            $previewRows[] = [
                'row' => $rowNum,
                'name' => $name,
                'phone' => $phone,
                'city' => trim((string) ($row[$cityCol] ?? '')),
                'status' => 'new',
                'error' => null,
            ];
        }

        return ['summary' => $summary, 'rows' => $previewRows];
    }

    /**
     * Parse CSV into raw row arrays (positional, no header mapping).
     *
     * @return array<int, array<int, mixed>>
     */
    private function parseCsvRawRows(UploadedFile $file, bool $skipHeader = true): array
    {
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        if ($skipHeader && $this->isHeaderRow($rows[0] ?? [])) {
            array_shift($rows);
        }

        return $rows;
    }

    /**
     * Parse XLSX into raw row arrays (positional).
     *
     * @return array<int, array<int, mixed>>
     */
    private function parseXlsxRawRows(UploadedFile $file, bool $skipHeader = true): array
    {
        $rows = [];
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            $highestColIndex = Coordinate::columnIndexFromString(
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
            Log::error('Telesales XLSX preview parse failed: '.$e->getMessage());

            return [];
        }

        if ($skipHeader && $this->isHeaderRow($rows[0] ?? [])) {
            array_shift($rows);
        }

        return $rows;
    }

    private function importCsv(UploadedFile $file, int $userId, array $mapping): array
    {
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        // Skip header if first row looks like headers
        if ($this->isHeaderRow($rows[0] ?? [])) {
            array_shift($rows);
        }

        return $this->processRows($rows, $userId, $mapping);
    }

    private function importXlsx(UploadedFile $file, int $userId, array $mapping): array
    {
        $rows = [];
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            // Determine column count dynamically (ISS-014)
            $highestColIndex = Coordinate::columnIndexFromString(
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
            Log::error('Telesales XLSX import failed: '.$e->getMessage());

            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Failed to read XLSX: '.$e->getMessage()]];
        }

        // Skip header if first row looks like headers
        if ($this->isHeaderRow($rows[0] ?? [])) {
            array_shift($rows);
        }

        return $this->processRows($rows, $userId, $mapping);
    }

    private function processRows(array $rows, int $userId, array $mapping): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($rows as $index => $row) {
            if (empty(array_filter($row))) {
                continue;
            }

            $result = $this->processRow($row, $userId, $mapping);

            if (isset($result['error'])) {
                $stats['errors'][] = 'Row '.($index + 1).": {$result['error']}";
                $stats['skipped']++;
            } elseif ($result['action'] === 'created') {
                $stats['created']++;
            } elseif ($result['action'] === 'updated') {
                $stats['updated']++;
            }
        }

        Log::info('Telesales import complete', [
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

    private function processRow(array $row, int $userId, array $mapping): array
    {
        $name = trim((string) ($row[$mapping['name']] ?? ''));
        $phoneRaw = $row[$mapping['phone']] ?? '';
        $address = trim((string) ($row[$mapping['address']] ?? ''));
        $province = $this->normalizeRegion($row[$mapping['province']] ?? '');
        $city = $this->normalizeRegion($row[$mapping['city']] ?? '');
        $barangay = $this->normalizeRegion($row[$mapping['barangay']] ?? '');
        $amount = $this->parseAmount($row[$mapping['amount']] ?? null);
        $product = trim((string) ($row[$mapping['product_name']] ?? ''));
        $orderStatus = trim((string) ($row[$mapping['order_status']] ?? ''));

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

        // Source + demographics + customer history quality score (LeadScoringService)
        $qualityScore = $this->scoringService->scoreFromImportData([
            'source' => LeadSource::TELESALES_IMPORT->value,
            'address' => $address,
            'city' => $city,
            'state' => $province,
            'barangay' => $barangay,
            'phone' => $phone,
            'product_name' => $product,
            'amount' => $amount,
        ], $customer);

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

        if (is_float($raw) || (is_string($raw) && str_contains(strtolower((string) $raw), 'e'))) {
            $raw = (string) (int) round((float) $raw);
        } else {
            $raw = (string) $raw;
        }

        $digits = preg_replace('/\D/', '', $raw);

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
            return '+63'.substr($digits, 1);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '639')) {
            return '+'.$digits;
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
        if (empty($value) || ! is_numeric($value)) {
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
