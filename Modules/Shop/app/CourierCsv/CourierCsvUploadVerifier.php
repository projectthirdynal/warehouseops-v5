<?php

declare(strict_types=1);

namespace Modules\Shop\CourierCsv;

use Modules\Shop\Models\CourierExportBatch;

/**
 * Verifies uploaded CSV files against courier schemas and existing export batches.
 * Provides a detailed verification report including encoding, structure, data,
 * and batch comparison checks.
 */
final class CourierCsvUploadVerifier
{
    public function __construct(
        private readonly CourierCsvSchemaRegistry $schemas,
        private readonly CourierCsvTemplateBuilder $templates,
        private readonly CourierCsvEncodingChecker $encodingChecker,
    ) {}

    /**
     * Verify an uploaded CSV file against a courier schema.
     *
     * @return array<string, mixed>
     */
    public function verify(string $csvContent, string $courierCode): array
    {
        $encoding = $this->encodingChecker->check($csvContent);
        $normalized = $this->encodingChecker->normalize($csvContent);

        $schema = $this->schemas->resolve($courierCode);
        $customSchema = $this->templates->resolveSchema($courierCode);
        $effectiveSchema = $customSchema ?? $schema;
        $expectedHeaders = $effectiveSchema->headers();
        $requiredFields = $effectiveSchema->requiredFields();

        $rows = $this->parseCsv($normalized);

        if ($rows === []) {
            return [
                'verified' => false,
                'courier_code' => strtoupper($courierCode),
                'using_custom_template' => $customSchema !== null,
                'encoding' => $encoding,
                'errors' => ['CSV file is empty or could not be parsed.'],
                'warnings' => [],
                'row_count' => 0,
                'header_check' => null,
                'data_check' => null,
                'summary' => null,
            ];
        }

        $headers = array_shift($rows);
        $headers = array_map(fn ($h) => trim($h), $headers);

        $headerCheck = $this->checkHeaders($headers, $expectedHeaders);

        $dataCheck = $this->checkRowData($rows, $headers, $effectiveSchema, $courierCode);

        $warnings = [];

        if ($headerCheck['extra_columns'] !== []) {
            $warnings[] = 'Extra columns detected: '.implode(', ', $headerCheck['extra_columns']).'. These will be ignored by the courier.';
        }

        if ($dataCheck['empty_row_count'] > 0) {
            $warnings[] = "{$dataCheck['empty_row_count']} empty row(s) found.";
        }

        $errors = array_merge(
            $headerCheck['missing_columns'] !== [] ? ['Missing required columns: '.implode(', ', $headerCheck['missing_columns'])] : [],
            $encoding['issues'],
            $dataCheck['errors'],
        );

        $verified = $headerCheck['match']
            && $headerCheck['missing_columns'] === []
            && $encoding['valid']
            && $dataCheck['errors'] === [];

        $totalRows = count($rows);
        $validRows = $totalRows - $dataCheck['invalid_row_count'];

        return [
            'verified' => $verified,
            'courier_code' => strtoupper($courierCode),
            'using_custom_template' => $customSchema !== null,
            'encoding' => $encoding,
            'errors' => $errors,
            'warnings' => $warnings,
            'row_count' => $totalRows,
            'header_check' => $headerCheck,
            'data_check' => $dataCheck,
            'summary' => [
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'invalid_rows' => $dataCheck['invalid_row_count'],
                'empty_rows' => $dataCheck['empty_row_count'],
                'column_count' => count($headers),
                'expected_column_count' => count($expectedHeaders),
                'pass_rate' => $totalRows > 0 ? round($validRows / $totalRows * 100, 1) : 0,
            ],
        ];
    }

    /**
     * Verify an uploaded CSV against an existing export batch.
     *
     * @return array<string, mixed>
     */
    public function verifyAgainstBatch(string $csvContent, CourierExportBatch $batch): array
    {
        $baseVerification = $this->verify($csvContent, $batch->courier_code);

        $batchRows = $batch->rows()->orderBy('row_number')->get();
        $normalized = $this->encodingChecker->normalize($csvContent);
        $parsedRows = $this->parseCsv($normalized);

        $batchComparison = [
            'batch_id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'batch_row_count' => $batchRows->count(),
            'uploaded_row_count' => count($parsedRows) > 0 ? count($parsedRows) - 1 : 0,
            'row_count_match' => false,
            'missing_rows' => [],
            'extra_rows' => [],
            'order_mismatches' => [],
        ];

        $uploadedDataRows = $parsedRows;
        if ($uploadedDataRows !== []) {
            array_shift($uploadedDataRows);
        }

        $batchComparison['row_count_match'] = $batchRows->count() === count($uploadedDataRows);

        $batchOrderNumbers = $batchRows->pluck('order_number', 'row_number')->toArray();
        $uploadedOrderNumbers = [];

        $schema = $this->schemas->resolve($batch->courier_code);
        $customSchema = $this->templates->resolveSchema($batch->courier_code);
        $effectiveSchema = $customSchema ?? $schema;
        $orderNumberIndex = null;
        foreach ($effectiveSchema->columns as $i => $col) {
            if ($col->field === 'order_number') {
                $orderNumberIndex = $i;
                break;
            }
        }

        if ($orderNumberIndex !== null) {
            foreach ($uploadedDataRows as $i => $row) {
                $uploadedOrderNumbers[$i + 2] = $row[$orderNumberIndex] ?? null;
            }

            $batchSet = array_values(array_filter($batchOrderNumbers));
            $uploadedSet = array_values(array_filter($uploadedOrderNumbers));

            $batchComparison['missing_rows'] = array_values(array_diff($batchSet, $uploadedSet));
            $batchComparison['extra_rows'] = array_values(array_diff($uploadedSet, $batchSet));

            foreach ($uploadedOrderNumbers as $rowNum => $orderNum) {
                if ($orderNum !== null && in_array($orderNum, $batchSet, true)) {
                    $batchRow = $batchRows->firstWhere('order_number', $orderNum);
                    if ($batchRow) {
                        $rowIndex = $rowNum - 2;
                        if (isset($uploadedDataRows[$rowIndex])) {
                            $mismatches = $this->compareRowValues(
                                $uploadedDataRows[$rowIndex],
                                $effectiveSchema,
                                $batchRow,
                            );
                            if ($mismatches !== []) {
                                $batchComparison['order_mismatches'][] = [
                                    'order_number' => $orderNum,
                                    'row_number' => $rowNum,
                                    'mismatches' => $mismatches,
                                ];
                            }
                        }
                    }
                }
            }
        }

        $baseVerification['batch_comparison'] = $batchComparison;
        $baseVerification['verified'] = $baseVerification['verified']
            && $batchComparison['row_count_match']
            && $batchComparison['missing_rows'] === []
            && $batchComparison['extra_rows'] === []
            && $batchComparison['order_mismatches'] === [];

        return $baseVerification;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $expectedHeaders
     * @return array<string, mixed>
     */
    private function checkHeaders(array $headers, array $expectedHeaders): array
    {
        $missing = [];
        $extra = [];

        foreach ($expectedHeaders as $expected) {
            if (! in_array($expected, $headers, true)) {
                $missing[] = $expected;
            }
        }

        foreach ($headers as $actual) {
            if (! in_array($actual, $expectedHeaders, true)) {
                $extra[] = $actual;
            }
        }

        return [
            'actual' => $headers,
            'expected' => $expectedHeaders,
            'match' => $headers === $expectedHeaders,
            'missing_columns' => $missing,
            'extra_columns' => $extra,
        ];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, string>  $headers
     * @return array<string, mixed>
     */
    private function checkRowData(array $rows, array $headers, CourierCsvSchema $schema, string $courierCode): array
    {
        $errors = [];
        $invalidRowCount = 0;
        $emptyRowCount = 0;
        $rowIssues = [];

        $expectedColumnCount = count($schema->headers());
        $fieldToHeaderIndex = [];
        foreach ($schema->columns as $i => $col) {
            $fieldToHeaderIndex[$col->field] = $i;
        }

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2;
            $nonEmpty = array_filter($row, fn ($v) => trim((string) $v) !== '');

            if ($nonEmpty === []) {
                $emptyRowCount++;

                continue;
            }

            $rowErrors = [];

            if (count($row) !== $expectedColumnCount) {
                $rowErrors[] = "Column count mismatch: expected {$expectedColumnCount}, got ".count($row);
            }

            foreach ($schema->columns as $colIndex => $col) {
                if (! isset($row[$colIndex])) {
                    if ($col->required) {
                        $rowErrors[] = "Missing column: {$col->header}";
                    }

                    continue;
                }

                $value = trim((string) $row[$colIndex]);

                if ($col->required && $value === '') {
                    $rowErrors[] = "Empty required field: {$col->header}";
                }

                if ($value !== '' && $col->field === 'phone_number') {
                    $digits = preg_replace('/\D/', '', $value) ?: '';
                    if (strlen($digits) < 10 || strlen($digits) > 13) {
                        $rowErrors[] = "Invalid phone number format: {$value}";
                    }
                }

                if ($value !== '' && $col->field === 'cod_amount') {
                    if (! is_numeric(str_replace([',', ' '], '', $value))) {
                        $rowErrors[] = "Invalid COD amount: {$value}";
                    }
                }

                if ($value !== '' && $col->field === 'quantity') {
                    if (! is_numeric($value) || (int) $value < 1) {
                        $rowErrors[] = "Invalid quantity: {$value}";
                    }
                }
            }

            if ($rowErrors !== []) {
                $invalidRowCount++;
                $errors[] = "Row {$rowNumber}: ".implode('; ', $rowErrors);
                $rowIssues[] = [
                    'row_number' => $rowNumber,
                    'errors' => $rowErrors,
                ];

                if (count($errors) >= 50) {
                    $errors[] = '... and more errors (truncated at 50).';
                    break;
                }
            }
        }

        return [
            'invalid_row_count' => $invalidRowCount,
            'empty_row_count' => $emptyRowCount,
            'errors' => $errors,
            'row_issues' => $rowIssues,
        ];
    }

    /**
     * @param  array<int, string>  $uploadedRow
     * @return array<int, array{field: string, expected: string, actual: string}>
     */
    private function compareRowValues(array $uploadedRow, CourierCsvSchema $schema, $batchRow): array
    {
        $mismatches = [];

        foreach ($schema->columns as $i => $col) {
            $uploadedValue = trim((string) ($uploadedRow[$i] ?? ''));
            $batchValue = trim((string) $this->resolveBatchRowField($batchRow, $col->field));

            if ($col->field === 'cod_amount') {
                $uploadedNum = (float) str_replace([',', ' '], '', $uploadedValue);
                $batchNum = (float) str_replace([',', ' '], '', $batchValue);
                if (abs($uploadedNum - $batchNum) > 0.01) {
                    $mismatches[] = [
                        'field' => $col->field,
                        'expected' => $batchValue,
                        'actual' => $uploadedValue,
                    ];
                }
            } elseif ($uploadedValue !== $batchValue && $batchValue !== '') {
                $mismatches[] = [
                    'field' => $col->field,
                    'expected' => $batchValue,
                    'actual' => $uploadedValue,
                ];
            }
        }

        return $mismatches;
    }

    private function resolveBatchRowField($row, string $field): mixed
    {
        return match ($field) {
            'order_number' => $row->order?->order_number ?? $row->order_id,
            'receiver_name' => $row->receiver_name,
            'phone_number' => $row->phone_number,
            'complete_address' => $row->complete_address,
            'province' => $row->province,
            'city' => $row->city,
            'barangay' => $row->barangay,
            'landmark' => $row->landmark,
            'product_name' => $row->product_name,
            'quantity' => $row->quantity,
            'cod_amount' => $row->cod_amount,
            'remarks' => $row->remarks,
            'sender_name' => config('services.shop.sender_name', ''),
            'sender_phone' => config('services.shop.sender_phone', ''),
            'sender_address' => config('services.shop.sender_address', ''),
            'sender_province' => config('services.shop.sender_province', ''),
            'sender_city' => config('services.shop.sender_city', ''),
            default => '',
        };
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $content): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
