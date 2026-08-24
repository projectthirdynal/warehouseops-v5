<?php

namespace App\Services;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Enums\LeadStatus;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Events\LeadCreated;
use App\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LeadImportService
{
    public function __construct(
        private LeadAuditService $auditService,
        private LeadScoringService $scoringService
    ) {}

    /**
     * Import leads from CSV or XLSX file.
     */
    public function import(UploadedFile $file, int $userId): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'])) {
            return $this->importXlsx($file, $userId);
        }

        return $this->importCsv($file, $userId);
    }

    /**
     * Preview import: parse file, detect duplicates, return validation results without writing.
     *
     * @return array{summary: array, rows: array}
     */
    public function preview(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $rows = in_array($extension, ['xlsx', 'xls'])
            ? $this->parseXlsxRows($file)
            : $this->parseCsvRows($file);

        $previewRows = [];
        $seenPhones = [];
        $summary = ['total' => 0, 'new' => 0, 'duplicate_db' => 0, 'duplicate_file' => 0, 'errors' => 0];

        // Pre-fetch all existing phones from the file to check DB in one query
        $phonesToCheck = [];
        foreach ($rows as $rowData) {
            $phone = $this->normalizePhone($rowData['phone_raw'] ?? ($rowData['phone'] ?? null));
            if ($phone) {
                $phonesToCheck[] = $phone;
            }
        }
        $existingPhones = Lead::whereIn('phone', array_unique($phonesToCheck))
            ->whereNotIn('pool_status', [PoolStatus::EXHAUSTED])
            ->pluck('phone')
            ->flip();

        foreach ($rows as $row) {
            $summary['total']++;
            $name = trim($row['name'] ?? '');
            $phoneRaw = $row['phone_raw'] ?? ($row['phone'] ?? null);
            $phone = $this->normalizePhone($phoneRaw);

            if (empty($name)) {
                $summary['errors']++;
                $previewRows[] = [
                    'row' => $row['_row_num'] ?? $summary['total'],
                    'name' => '',
                    'phone' => $phoneRaw ? (string) $phoneRaw : '',
                    'city' => $row['city'] ?? '',
                    'status' => 'error',
                    'error' => 'Name is required',
                ];

                continue;
            }

            if (! $phone) {
                $summary['errors']++;
                $previewRows[] = [
                    'row' => $row['_row_num'] ?? $summary['total'],
                    'name' => $name,
                    'phone' => $phoneRaw ? (string) $phoneRaw : '',
                    'city' => $row['city'] ?? '',
                    'status' => 'error',
                    'error' => 'Invalid phone number',
                ];

                continue;
            }

            // Check in-file duplicate
            if (isset($seenPhones[$phone])) {
                $summary['duplicate_file']++;
                $previewRows[] = [
                    'row' => $row['_row_num'] ?? $summary['total'],
                    'name' => $name,
                    'phone' => $phone,
                    'city' => $row['city'] ?? '',
                    'status' => 'duplicate_file',
                    'error' => null,
                ];

                continue;
            }

            $seenPhones[$phone] = true;

            // Check DB duplicate
            if ($existingPhones->has($phone)) {
                $summary['duplicate_db']++;
                $previewRows[] = [
                    'row' => $row['_row_num'] ?? $summary['total'],
                    'name' => $name,
                    'phone' => $phone,
                    'city' => $row['city'] ?? '',
                    'status' => 'duplicate_db',
                    'error' => null,
                ];

                continue;
            }

            $summary['new']++;
            $previewRows[] = [
                'row' => $row['_row_num'] ?? $summary['total'],
                'name' => $name,
                'phone' => $phone,
                'city' => $row['city'] ?? '',
                'status' => 'new',
                'error' => null,
            ];
        }

        return ['summary' => $summary, 'rows' => $previewRows];
    }

    /**
     * Parse CSV file into normalized row data array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseCsvRows(UploadedFile $file): array
    {
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_shift($rows);
        $header = array_map(fn ($h) => strtolower(trim($h)), $header);
        $result = [];

        foreach ($rows as $index => $row) {
            if (empty(array_filter($row))) {
                continue;
            }
            if (count($row) !== count($header)) {
                $result[] = ['_row_num' => $index + 2, 'name' => '', 'phone_raw' => null, '_error' => 'Column count mismatch'];

                continue;
            }
            $data = array_combine($header, $row);
            $data['_row_num'] = $index + 2;
            $result[] = $data;
        }

        return $result;
    }

    /**
     * Parse XLSX file into normalized row data array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseXlsxRows(UploadedFile $file): array
    {
        $result = [];

        try {
            $reader = new IOFactory;
            $spreadsheet = $reader::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            for ($rowNum = 1; $rowNum <= $highestRow; $rowNum++) {
                $row = [];
                for ($col = 1; $col <= 14; $col++) {
                    $cell = $sheet->getCellByColumnAndRow($col, $rowNum);
                    $row[] = $cell->getValue();
                }

                if (empty(array_filter($row))) {
                    continue;
                }

                $result[] = [
                    '_row_num' => $rowNum,
                    'name' => $row[0] ?? null,
                    'phone_raw' => $row[1] ?? null,
                    'address' => $row[2] ?? null,
                    'state' => $row[3] ?? null,
                    'city' => $row[4] ?? null,
                    'barangay' => $row[5] ?? null,
                    'amount' => $row[11] ?? null,
                    'product_name' => $row[12] ?? null,
                    'lead_status' => $row[13] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::error('XLSX preview parse failed', ['exception' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Import from CSV with header row (legacy format).
     */
    private function importCsv(UploadedFile $file, int $userId): array
    {
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_shift($rows);
        $header = array_map(fn ($h) => strtolower(trim($h)), $header);

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($rows as $index => $row) {
            if (empty(array_filter($row))) {
                continue;
            }

            if (count($row) !== count($header)) {
                $stats['errors'][] = 'Row '.($index + 2).': Column count mismatch';
                $stats['skipped']++;

                continue;
            }

            $data = array_combine($header, $row);
            $result = $this->processRow($data, $userId, $index + 2);
            $stats[$result['action'] === 'created' ? 'created' : ($result['action'] === 'updated' ? 'updated' : 'skipped')]
                += ($result['action'] === 'created' || $result['action'] === 'updated') ? 1 : 0;
            if ($result['error']) {
                $stats['errors'][] = $result['error'];
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Import from XLSX without header row (new format per architecture §2.2).
     *
     * Column mapping:
     *   A (0): customer_name    B (1): phone_number    C (2): full_address
     *   D (3): province          E (4): city_municipality  F (5): barangay
     *   G–K (6–10): unused       L (11): amount         M (12): product_name
     *   N (13): lead_status
     */
    private function importXlsx(UploadedFile $file, int $userId): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        try {
            $reader = new IOFactory;
            $spreadsheet = $reader::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            for ($rowNum = 1; $rowNum <= $highestRow; $rowNum++) {
                $row = [];
                for ($col = 1; $col <= 14; $col++) {
                    $cell = $sheet->getCellByColumnAndRow($col, $rowNum);
                    $row[] = $cell->getValue();
                }

                // Skip completely empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                $data = [
                    'name' => $row[0] ?? null,
                    'phone_raw' => $row[1] ?? null,
                    'address' => $row[2] ?? null,
                    'state' => $row[3] ?? null,
                    'city' => $row[4] ?? null,
                    'barangay' => $row[5] ?? null,
                    'amount' => $row[11] ?? null,
                    'product_name' => $row[12] ?? null,
                    'lead_status' => $row[13] ?? null,
                ];

                $result = $this->processRow($data, $userId, $rowNum);
                if ($result['action'] === 'created') {
                    $stats['created']++;
                } elseif ($result['action'] === 'updated') {
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
                if ($result['error']) {
                    $stats['errors'][] = "Row {$rowNum}: {$result['error']}";
                }
            }
        } catch (\Throwable $e) {
            Log::error('XLSX import failed', ['exception' => $e->getMessage()]);
            $stats['errors'][] = 'File parsing error: '.$e->getMessage();
        }

        return $stats;
    }

    /**
     * Process a single row and create/update the lead.
     *
     * @return array{action: string, error: ?string}
     */
    private function processRow(array $data, int $userId, int $rowNum): array
    {
        $name = trim($data['name'] ?? '');
        $phoneRaw = $data['phone_raw'] ?? ($data['phone'] ?? null);

        if (empty($name)) {
            return ['action' => 'skipped', 'error' => "Row {$rowNum}: customer_name is required"];
        }

        $phone = $this->normalizePhone($phoneRaw);
        if (! $phone) {
            return ['action' => 'skipped', 'error' => "Row {$rowNum}: phone number could not be normalized (raw: {$phoneRaw})"];
        }

        // Find or create Customer
        $customer = Customer::firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $name,
                'total_orders' => 0,
                'successful_orders' => 0,
                'returned_orders' => 0,
                'success_rate' => 0,
            ]
        );

        if ($customer->is_blacklisted) {
            return ['action' => 'skipped', 'error' => null];
        }

        // Resolve status
        $leadStatus = null;
        if (! empty($data['lead_status'])) {
            $leadStatus = LeadStatus::tryFrom(strtoupper(trim($data['lead_status'])));
        }

        // Compute quality score on import (source + demographics + customer history)
        $qualityScore = $this->scoringService->scoreFromImportData(
            array_merge($data, ['source' => $data['source'] ?? 'XLSX_IMPORT', 'phone' => $phone]),
            $customer
        );

        // Check whether the customer is blocked by an active or recently delivered order.
        $cooldown = app(CustomerOrderCooldownService::class)->forCustomer($customer);

        // Check for existing non-exhausted lead
        $existing = Lead::where('phone', $phone)
            ->whereNotIn('pool_status', [PoolStatus::EXHAUSTED])
            ->first();

        if ($existing) {
            $existing->update([
                'name' => $name,
                'customer_id' => $customer->id,
                'address' => $data['address'] ?? $existing->address,
                'city' => $this->normalizeRegion($data['city'] ?? $existing->city),
                'state' => $this->normalizeRegion($data['state'] ?? $existing->state),
                'barangay' => $this->normalizeRegion($data['barangay'] ?? $existing->barangay),
                'product_name' => $data['product_name'] ?? $existing->product_name,
                'product_brand' => $data['product_brand'] ?? $existing->product_brand,
                'amount' => isset($data['amount']) && is_numeric($data['amount'])
                    ? (float) $data['amount']
                    : $existing->amount,
                'notes' => $data['notes'] ?? $existing->notes,
                'source' => $data['source'] ?? $existing->source ?? 'XLSX_IMPORT',
                'status' => $leadStatus ?? $existing->status,
                'quality_score' => $qualityScore,
                'last_scored_at' => now(),
                'cooldown_until' => $cooldown['until'],
                'pool_status' => $cooldown['blocked']
                    ? PoolStatus::COOLDOWN
                    : ($existing->pool_status === PoolStatus::COOLDOWN
                        ? PoolStatus::AVAILABLE
                        : $existing->pool_status),
            ]);

            return ['action' => 'updated', 'error' => null];
        }

        $lead = Lead::create([
            'customer_id' => $customer->id,
            'name' => $name,
            'phone' => $phone,
            'address' => $data['address'] ?? null,
            'city' => $this->normalizeRegion($data['city'] ?? null),
            'state' => $this->normalizeRegion($data['state'] ?? null),
            'barangay' => $this->normalizeRegion($data['barangay'] ?? null),
            'product_name' => $data['product_name'] ?? null,
            'product_brand' => $data['product_brand'] ?? null,
            'amount' => isset($data['amount']) && is_numeric($data['amount'])
                ? (float) $data['amount']
                : null,
            'notes' => $data['notes'] ?? null,
            'source' => $data['source'] ?? LeadSource::XLSX_IMPORT,
            'status' => $leadStatus ?? LeadStatus::NEW,
            'pool_status' => $cooldown['blocked'] ? PoolStatus::COOLDOWN : PoolStatus::AVAILABLE,
            'cooldown_until' => $cooldown['until'],
            'quality_score' => $qualityScore,
            'last_scored_at' => now(),
            'uploaded_by' => $userId,
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'LEAD_CREATED',
            metadata: ['source' => 'xlsx_import', 'uploaded_by' => $userId, 'quality_score' => $qualityScore]
        );

        LeadCreated::dispatch($lead);

        return ['action' => 'created', 'error' => null];
    }

    /**
     * Normalize Philippine phone numbers from Excel numeric/scientific notation.
     */
    private function normalizePhone(mixed $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }

        // Handle Excel scientific notation (e.g. 9.772053856E9)
        if (is_float($raw) || (is_string($raw) && str_contains(strtolower((string) $raw), 'e'))) {
            $raw = (string) (int) round((float) $raw);
        }

        $digits = preg_replace('/\D/', '', (string) $raw);

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        // Normalise to +63 format (matches TelesalesLeadImportService — BUG-11)
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

    /**
     * Normalize region strings to uppercase.
     */
    private function normalizeRegion(?string $region): ?string
    {
        return $region ? strtoupper(trim($region)) : null;
    }
}
