<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use App\Domain\Product\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class AdjustmentBulkImportService
{
    public const REQUIRED_HEADERS = [
        'item_type',
        'sku',
        'warehouse_code',
        'quantity_after',
        'reason_code',
    ];

    public const OPTIONAL_HEADERS = [
        'variant_sku',
        'reason_notes',
    ];

    public const VALID_ITEM_TYPES = ['product', 'supply'];

    public const VALID_REASON_CODES = [
        'CYCLE_COUNT',
        'DAMAGE',
        'LOSS',
        'THEFT',
        'EXPIRY',
        'QUALITY_REJECT',
        'SYSTEM_CORRECTION',
        'INITIAL_STOCK',
        'TRANSFER_RECEIPT',
        'OTHER',
    ];

    /**
     * Parse a CSV file into rows.
     *
     * @return array<string, mixed>
     */
    public function parseCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [
                'headers' => [],
                'rows' => [],
                'errors' => ['Cannot open file.'],
            ];
        }

        $headers = fgetcsv($handle, 0, ',');
        if ($headers === false) {
            fclose($handle);

            return [
                'headers' => [],
                'rows' => [],
                'errors' => ['Empty CSV file.'],
            ];
        }

        $headers = array_map(fn ($h) => strtolower(trim($h)), $headers);

        // Validate required headers
        $missing = array_diff(self::REQUIRED_HEADERS, $headers);
        if (! empty($missing)) {
            fclose($handle);

            return [
                'headers' => $headers,
                'rows' => [],
                'errors' => ['Missing required columns: '.implode(', ', $missing)],
            ];
        }

        $rows = [];
        $rowNumber = 1;
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            if (count(array_filter($row)) === 0) {
                $rowNumber++;

                continue;
            }

            $data = [];
            foreach ($headers as $idx => $header) {
                $data[$header] = isset($row[$idx]) ? trim($row[$idx]) : '';
            }
            $data['_row_number'] = $rowNumber;
            $rows[] = $data;
            $rowNumber++;
        }
        fclose($handle);

        return [
            'headers' => $headers,
            'rows' => $rows,
            'errors' => [],
        ];
    }

    /**
     * Validate parsed rows and produce a preview.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function validateRows(array $rows): array
    {
        $warehouses = Warehouse::where('is_active', true)->get()->keyBy('code');
        $products = Product::where('is_active', true)->get()->keyBy('sku');
        $supplies = Supply::where('is_active', true)->get()->keyBy('sku');
        $variants = ProductVariant::where('is_active', true)->get()->keyBy('sku');

        $valid = [];
        $errors = [];
        $warnings = [];

        foreach ($rows as $row) {
            $rowErrors = [];
            $rowNum = $row['_row_number'];

            // item_type
            $itemType = strtolower($row['item_type'] ?? '');
            if (! in_array($itemType, self::VALID_ITEM_TYPES)) {
                $rowErrors[] = "Invalid item_type '{$row['item_type']}'. Must be: product or supply.";
            }

            // sku
            $sku = $row['sku'] ?? '';
            if (empty($sku)) {
                $rowErrors[] = 'SKU is required.';
            }

            // warehouse_code
            $warehouseCode = $row['warehouse_code'] ?? '';
            if (empty($warehouseCode)) {
                $rowErrors[] = 'warehouse_code is required.';
            } elseif (! $warehouses->has($warehouseCode)) {
                $rowErrors[] = "Warehouse '{$warehouseCode}' not found or inactive.";
            }

            // quantity_after
            $qtyAfter = $row['quantity_after'] ?? '';
            if (! is_numeric($qtyAfter) || (int) $qtyAfter < 0) {
                $rowErrors[] = "quantity_after must be a non-negative integer, got '{$qtyAfter}'.";
            }

            // reason_code
            $reasonCode = $row['reason_code'] ?? '';
            if (empty($reasonCode)) {
                $rowErrors[] = 'reason_code is required.';
            } elseif (! in_array($reasonCode, self::VALID_REASON_CODES)) {
                $rowErrors[] = "Invalid reason_code '{$reasonCode}'. Valid: ".implode(', ', self::VALID_REASON_CODES);
            }

            // variant_sku (optional, only for products)
            $variantSku = $row['variant_sku'] ?? '';
            $variantId = null;
            if (! empty($variantSku)) {
                if ($itemType !== 'product') {
                    $warnings[] = "Row {$rowNum}: variant_sku is ignored for item_type '{$itemType}'.";
                } elseif (! $variants->has($variantSku)) {
                    $rowErrors[] = "Variant SKU '{$variantSku}' not found or inactive.";
                } else {
                    $variantId = $variants->get($variantSku)->id;
                }
            }

            // Look up item
            $item = null;
            $itemId = null;
            if (empty($rowErrors) || count($rowErrors) < 3) {
                if ($itemType === 'product' && ! empty($sku)) {
                    $item = $products->get($sku);
                    $itemId = $item?->id;
                    if (! $item && empty($rowErrors)) {
                        $rowErrors[] = "Product SKU '{$sku}' not found or inactive.";
                    }
                } elseif ($itemType === 'supply' && ! empty($sku)) {
                    $item = $supplies->get($sku);
                    $itemId = $item?->id;
                    if (! $item && empty($rowErrors)) {
                        $rowErrors[] = "Supply SKU '{$sku}' not found or inactive.";
                    }
                }
            }

            // Compute current stock for preview
            $currentStock = null;
            $variance = null;
            $warehouse = $warehouses->get($warehouseCode);
            if ($warehouse && $itemId && is_numeric($qtyAfter)) {
                $currentStock = $this->getCurrentStock($itemType, $itemId, $variantId, $warehouse->id);
                $variance = (int) $qtyAfter - $currentStock;
            }

            $previewRow = [
                'row_number' => $rowNum,
                'item_type' => $itemType,
                'sku' => $sku,
                'item_name' => $item?->name ?? '-',
                'variant_sku' => $variantSku ?: null,
                'variant_id' => $variantId,
                'warehouse_code' => $warehouseCode,
                'warehouse_name' => $warehouse?->name ?? '-',
                'quantity_before' => $currentStock,
                'quantity_after' => is_numeric($qtyAfter) ? (int) $qtyAfter : null,
                'variance' => $variance,
                'reason_code' => $reasonCode,
                'reason_notes' => $row['reason_notes'] ?? null,
                'is_valid' => empty($rowErrors),
                'errors' => $rowErrors,
            ];

            if (empty($rowErrors)) {
                $valid[] = $previewRow;
            } else {
                $errors[] = [
                    'row_number' => $rowNum,
                    'sku' => $sku,
                    'errors' => $rowErrors,
                ];
            }
        }

        return [
            'valid_rows' => $valid,
            'error_rows' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'total_rows' => count($rows),
                'valid_count' => count($valid),
                'error_count' => count($errors),
                'warning_count' => count($warnings),
            ],
        ];
    }

    /**
     * Import validated rows as pending stock adjustments.
     *
     * @param  array<int, array<string, mixed>>  $validRows
     * @return array<string, mixed>
     */
    public function import(array $validRows, int $userId): array
    {
        $created = 0;
        $errors = [];

        DB::transaction(function () use ($validRows, $userId, &$created, &$errors): void {
            foreach ($validRows as $row) {
                try {
                    $warehouse = Warehouse::where('code', $row['warehouse_code'])->firstOrFail();

                    $productId = $row['item_type'] === 'product'
                        ? Product::where('sku', $row['sku'])->value('id')
                        : null;

                    $supplyId = $row['item_type'] === 'supply'
                        ? Supply::where('sku', $row['sku'])->value('id')
                        : null;

                    $quantityBefore = $this->getCurrentStock(
                        $row['item_type'],
                        $row['item_type'] === 'product' ? $productId : $supplyId,
                        $row['variant_id'],
                        $warehouse->id,
                    );

                    $quantityAfter = (int) $row['quantity_after'];

                    StockAdjustment::create([
                        'product_id' => $productId,
                        'supply_id' => $supplyId,
                        'variant_id' => $row['variant_id'],
                        'warehouse_id' => $warehouse->id,
                        'reason_code' => $row['reason_code'],
                        'reason_notes' => $row['reason_notes'] ?? null,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityAfter,
                        'variance' => $quantityAfter - $quantityBefore,
                        'status' => 'PENDING',
                        'submitted_by' => $userId,
                    ]);

                    $created++;
                } catch (\Exception $e) {
                    $errors[] = "Row {$row['row_number']}: ".$e->getMessage();
                }
            }
        });

        return [
            'created' => $created,
            'errors' => $errors,
        ];
    }

    /**
     * Generate a CSV template for download.
     */
    public function generateTemplate(): string
    {
        $headers = array_merge(self::REQUIRED_HEADERS, self::OPTIONAL_HEADERS);

        $exampleRows = [
            ['product', 'PROD-001', 'WH-A', '150', 'CYCLE_COUNT', '', 'Full cycle count'],
            ['supply', 'SUP-001', 'WH-A', '75', 'DAMAGE', '', 'Water damage in storage'],
            ['product', 'PROD-002', 'WH-B', '0', 'EXPIRY', 'VAR-001', 'Expired batch'],
        ];

        $csv = implode(',', $headers)."\n";
        foreach ($exampleRows as $row) {
            $csv .= implode(',', $row)."\n";
        }

        return $csv;
    }

    /**
     * Get the current stock for an item at a warehouse.
     */
    private function getCurrentStock(string $itemType, ?int $itemId, ?int $variantId, int $warehouseId): int
    {
        if ($itemType === 'supply') {
            return (int) (SupplyStock::where('supply_id', $itemId)
                ->where('warehouse_id', $warehouseId)
                ->value('current_stock') ?? 0);
        }

        return (int) (ProductStock::where('product_id', $itemId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->value('current_stock') ?? 0);
    }
}
