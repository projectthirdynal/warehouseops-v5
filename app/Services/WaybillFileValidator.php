<?php

namespace App\Services;

use App\Models\Upload;
use Rap2hpoutre\FastExcel\FastExcel;

class WaybillFileValidator
{
    protected Upload $upload;

    protected array $requiredHeaders = [];

    protected int $maxSampleRows = 20;

    protected int $maxValidationRows = 1000;

    public function __construct(Upload $upload)
    {
        $this->upload = $upload;
        $this->requiredHeaders = $this->getRequiredHeaders();
    }

    public function validate(): ValidationResult
    {
        $result = new ValidationResult;
        $filePath = storage_path('app/'.$this->upload->file_path);

        if (! file_exists($filePath)) {
            $result->addError('File not found on server.');

            return $result;
        }

        $detectedHeaders = [];
        $sampleRows = [];
        $rowCount = 0;
        $duplicates = [];
        $seenWaybills = [];

        try {
            (new FastExcel)->import($filePath, function ($row) use (
                &$detectedHeaders,
                &$sampleRows,
                &$rowCount,
                &$duplicates,
                &$seenWaybills,
                $result
            ) {
                $rowCount++;

                // Detect headers from first row
                if ($rowCount === 1) {
                    $detectedHeaders = array_keys($row);
                    $this->validateHeaders($detectedHeaders, $result);
                }

                // Collect sample rows
                if (count($sampleRows) < $this->maxSampleRows) {
                    $sampleRows[] = $row;
                }

                // Check for duplicates
                $waybillNumber = $this->extractWaybillNumber($row);
                if ($waybillNumber) {
                    if (isset($seenWaybills[$waybillNumber])) {
                        $duplicates[] = $waybillNumber;
                    }
                    $seenWaybills[$waybillNumber] = true;
                }

                // Stop after max validation rows
                if ($rowCount >= $this->maxValidationRows) {
                    throw new \RuntimeException('__validation_complete__');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__validation_complete__') {
                $result->addError('File could not be read: '.$e->getMessage());

                return $result;
            }
        } catch (\Throwable $e) {
            $result->addError('File could not be read: '.$e->getMessage());

            return $result;
        }

        // Set result data
        $result->setRowCount($rowCount);
        $result->setColumns($detectedHeaders);
        $result->setSampleRows($sampleRows);
        $result->setDuplicateCount(count(array_unique($duplicates)));

        if (count($duplicates) > 0) {
            $result->addWarning(
                count(array_unique($duplicates)).' duplicate waybill numbers detected in first '.
                min($rowCount, $this->maxValidationRows).' rows.'
            );
        }

        return $result;
    }

    protected function getRequiredHeaders(): array
    {
        return $this->upload->courier === 'jnt'
            ? ['Waybill Number', 'Order Status']
            : ['Tracking No.', 'Status'];
    }

    protected function validateHeaders(array $detectedHeaders, ValidationResult $result): void
    {
        foreach ($this->requiredHeaders as $required) {
            $found = false;
            foreach ($detectedHeaders as $header) {
                if (strtolower(trim($header)) === strtolower(trim($required))) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $result->addMissingHeader($required);
            }
        }
    }

    protected function extractWaybillNumber(array $row): ?string
    {
        $waybill = $row['Waybill Number'] ?? $row['Tracking No.'] ?? null;

        return $waybill ? trim((string) $waybill) : null;
    }
}

class ValidationResult
{
    protected bool $valid = true;

    protected array $errors = [];

    protected array $warnings = [];

    protected array $missingHeaders = [];

    protected int $rowCount = 0;

    protected array $columns = [];

    protected array $sampleRows = [];

    protected int $duplicateCount = 0;

    public function addError(string $error): void
    {
        $this->errors[] = $error;
        $this->valid = false;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function addMissingHeader(string $header): void
    {
        $this->missingHeaders[] = $header;
        $this->valid = false;
    }

    public function setRowCount(int $count): void
    {
        $this->rowCount = $count;
    }

    public function setColumns(array $columns): void
    {
        $this->columns = $columns;
    }

    public function setSampleRows(array $rows): void
    {
        $this->sampleRows = $rows;
    }

    public function setDuplicateCount(int $count): void
    {
        $this->duplicateCount = $count;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'missing_headers' => $this->missingHeaders,
            'row_count' => $this->rowCount,
            'columns' => $this->columns,
            'sample_rows' => $this->sampleRows,
            'duplicate_count' => $this->duplicateCount,
        ];
    }
}
