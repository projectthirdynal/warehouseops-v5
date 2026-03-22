<?php

namespace App\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
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

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            if (count($row) !== count($header)) {
                $errors[] = "Row " . ($index + 2) . ": Column count mismatch";
                $skipped++;
                continue;
            }

            $data = array_combine($header, $row);

            if (empty($data['name']) || empty($data['phone'])) {
                $errors[] = "Row " . ($index + 2) . ": name and phone are required";
                $skipped++;
                continue;
            }

            $phone = preg_replace('/[^0-9]/', '', $data['phone']);

            $existing = Lead::where('phone', $phone)->first();

            if ($existing) {
                $existing->update([
                    'name' => $data['name'],
                    'city' => $data['city'] ?? $existing->city,
                    'product_name' => $data['product_interest'] ?? $existing->product_name,
                    'source' => $data['source'] ?? 'CSV_IMPORT',
                ]);
                $updated++;
            } else {
                $lead = Lead::create([
                    'name' => $data['name'],
                    'phone' => $phone,
                    'city' => $data['city'] ?? null,
                    'state' => $data['state'] ?? null,
                    'product_name' => $data['product_interest'] ?? null,
                    'source' => $data['source'] ?? 'CSV_IMPORT',
                    'pool_status' => PoolStatus::AVAILABLE,
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
