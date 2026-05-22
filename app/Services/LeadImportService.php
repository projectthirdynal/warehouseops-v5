<?php

namespace App\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\Customer;
use Illuminate\Http\UploadedFile;

class LeadImportService
{
    public function __construct(
        private LeadAuditService $auditService
    ) {}

    public function import(UploadedFile $file, int $userId): array
    {
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_shift($rows);

        // Normalize headers: lowercase + trim
        $header = array_map(fn ($h) => strtolower(trim($h)), $header);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            // Skip empty trailing rows
            if (empty(array_filter($row))) {
                continue;
            }

            if (count($row) !== count($header)) {
                $errors[] = "Row " . ($index + 2) . ": Column count mismatch (expected " . count($header) . ", got " . count($row) . ")";
                $skipped++;
                continue;
            }

            $data = array_combine($header, $row);

            if (empty($data['name']) || empty($data['phone'])) {
                $errors[] = "Row " . ($index + 2) . ": 'name' and 'phone' are required";
                $skipped++;
                continue;
            }

            $phone = preg_replace('/[^0-9+]/', '', $data['phone']);

            // Normalize PH numbers: 9xxxxxxxxx → 09xxxxxxxxx
            if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
                $phone = '0' . $phone;
            }

            if (empty($phone)) {
                $errors[] = "Row " . ($index + 2) . ": Invalid phone number";
                $skipped++;
                continue;
            }

            // Find or create Customer record
            $customer = Customer::firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => $data['name'],
                    'total_orders' => 0,
                    'successful_orders' => 0,
                    'returned_orders' => 0,
                    'success_rate' => 0,
                ]
            );

            // Skip blacklisted customers
            if ($customer->is_blacklisted) {
                $skipped++;
                continue;
            }

            // Check for existing non-exhausted lead by phone
            $existing = Lead::where('phone', $phone)
                ->whereNotIn('pool_status', [PoolStatus::EXHAUSTED])
                ->first();

            if ($existing) {
                $existing->update([
                    'name' => $data['name'],
                    'customer_id' => $customer->id,
                    'city' => $data['city'] ?? $existing->city,
                    'state' => $data['state'] ?? $existing->state,
                    'barangay' => $data['barangay'] ?? $existing->barangay,
                    'product_name' => $data['product_interest'] ?? $data['product_name'] ?? $existing->product_name,
                    'product_brand' => $data['product_brand'] ?? $existing->product_brand,
                    'amount' => isset($data['amount']) && is_numeric($data['amount'])
                        ? (float) $data['amount']
                        : $existing->amount,
                    'notes' => $data['notes'] ?? $existing->notes,
                    'source' => $data['source'] ?? $existing->source,
                    // Refresh cooldown leads back to available
                    'pool_status' => $existing->pool_status === PoolStatus::COOLDOWN
                        ? PoolStatus::AVAILABLE
                        : $existing->pool_status,
                ]);
                $updated++;
            } else {
                $lead = Lead::create([
                    'customer_id' => $customer->id,
                    'name' => $data['name'],
                    'phone' => $phone,
                    'city' => $data['city'] ?? null,
                    'state' => $data['state'] ?? null,
                    'barangay' => $data['barangay'] ?? null,
                    'product_name' => $data['product_interest'] ?? $data['product_name'] ?? null,
                    'product_brand' => $data['product_brand'] ?? null,
                    'amount' => isset($data['amount']) && is_numeric($data['amount'])
                        ? (float) $data['amount']
                        : null,
                    'notes' => $data['notes'] ?? null,
                    'source' => $data['source'] ?? 'CSV_IMPORT',
                    'pool_status' => PoolStatus::AVAILABLE,
                    'uploaded_by' => $userId,
                ]);

                $this->auditService->log(
                    lead: $lead,
                    action: 'LEAD_CREATED',
                    metadata: ['source' => 'csv_import', 'uploaded_by' => $userId]
                );
                $created++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
