<?php

namespace App\Services;

use App\Domain\Lead\Enums\LeadStatus;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class LeadImportService
{
    public function __construct(
        private LeadAuditService $auditService
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
                $stats['errors'][] = "Row " . ($index + 2) . ": Column count mismatch";
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
            $reader = new \PhpOffice\PhpSpreadsheet\IOFactory();
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
            $stats['errors'][] = 'File parsing error: ' . $e->getMessage();
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

        // Compute quality score on import
        $qualityScore = $this->computeQualityScore($data);

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
                'pool_status' => $existing->pool_status === PoolStatus::COOLDOWN
                    ? PoolStatus::AVAILABLE
                    : $existing->pool_status,
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
            'source' => $data['source'] ?? 'XLSX_IMPORT',
            'status' => $leadStatus ?? LeadStatus::NEW,
            'pool_status' => PoolStatus::AVAILABLE,
            'quality_score' => $qualityScore,
            'last_scored_at' => now(),
            'uploaded_by' => $userId,
        ]);

        $this->auditService->log(
            lead: $lead,
            action: 'LEAD_CREATED',
            metadata: ['source' => 'xlsx_import', 'uploaded_by' => $userId, 'quality_score' => $qualityScore]
        );

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
        if (is_float($raw) || (is_string($raw) && str_contains(strtolower($raw), 'e'))) {
            $raw = (string) (int) round((float) $raw);
        }

        $phone = preg_replace('/[^0-9]/', '', (string) $raw);

        if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '0' . $phone;
        }

        if (strlen($phone) !== 11 || ! str_starts_with($phone, '09')) {
            return null;
        }

        return $phone;
    }

    /**
     * Normalize region strings to uppercase.
     */
    private function normalizeRegion(?string $region): ?string
    {
        return $region ? strtoupper(trim($region)) : null;
    }

    /**
     * Compute a quality score (0–100) for a lead based on data completeness.
     */
    private function computeQualityScore(array $data): int
    {
        $score = 50; // Base score

        // Full address present
        if (! empty($data['address']) || ! empty($data['city'])) {
            $score += 15;
        }

        // Province + city + barangay present
        if (! empty($data['state']) && ! empty($data['city']) && ! empty($data['barangay'])) {
            $score += 15;
        }

        // Product specified
        if (! empty($data['product_name'])) {
            $score += 10;
        }

        // Amount present
        if (! empty($data['amount']) && is_numeric($data['amount']) && (float) $data['amount'] > 0) {
            $score += 10;
        }

        return min(100, $score);
    }
}
