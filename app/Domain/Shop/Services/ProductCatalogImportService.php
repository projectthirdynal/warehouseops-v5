<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Product\Models\Product;
use App\Domain\Shop\Models\ShopPageProductMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductCatalogImportService
{
    /**
     * @return array{rows:int, products:int, mappings:int, skipped:int}
     */
    public function importCsv(string $path, bool $dryRun = false): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Catalog CSV is not readable: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Catalog CSV could not be opened: {$path}");
        }

        $summary = [
            'rows' => 0,
            'products' => 0,
            'mappings' => 0,
            'skipped' => 0,
        ];

        $header = fgetcsv($handle);
        $columns = $this->columnMap($header ?: []);

        $callback = function () use ($handle, $columns, &$summary) {
            $productsSeen = [];
            $mappingsSeen = [];

            while (($row = fgetcsv($handle)) !== false) {
                $summary['rows']++;

                $pageName = $this->value($row, $columns, 'pages');
                $brandName = $this->value($row, $columns, 'brand_name');
                $remarks = $this->value($row, $columns, 'remarks');

                if ($pageName === '' || $remarks === '') {
                    $summary['skipped']++;

                    continue;
                }

                $sku = $this->skuFromRemarks($remarks);
                $product = Product::query()->firstOrNew(['sku' => $sku]);

                if (! $product->exists) {
                    $product->selling_price = 0;
                    $product->cost_price = 0;
                    $product->weight_grams = 0;
                    $product->requires_qa = false;
                }

                $product->fill([
                    'name' => $remarks,
                    'brand' => $brandName !== '' ? $brandName : null,
                    'category' => 'Shop Catalog',
                    'description' => "Imported from Auto Encode product catalog.\nRemarks: {$remarks}",
                    'is_active' => true,
                ]);
                $product->save();

                $productsSeen[$product->id] = true;

                $normalizedPageName = ShopPageProductMapping::normalizePageName($pageName);

                ShopPageProductMapping::query()->updateOrCreate(
                    ['normalized_page_name' => $normalizedPageName],
                    [
                        'page_name' => $pageName,
                        'brand_name' => $brandName !== '' ? $brandName : null,
                        'remarks' => $remarks,
                        'product_id' => $product->id,
                        'variant_id' => null,
                        'is_active' => true,
                        'metadata' => [
                            'source' => 'auto_encode_page_name_sku_csv',
                            'catalog_sku' => $sku,
                        ],
                    ]
                );

                $mappingsSeen[$normalizedPageName] = true;
            }

            $summary['products'] = count($productsSeen);
            $summary['mappings'] = count($mappingsSeen);
        };

        try {
            if ($dryRun) {
                DB::transaction(function () use ($callback) {
                    $callback();

                    throw new RollbackCatalogImport;
                });
            } else {
                DB::transaction($callback);
            }
        } catch (RollbackCatalogImport) {
            // Dry-run rollback completed.
        } finally {
            fclose($handle);
        }

        return $summary;
    }

    /**
     * @param array<int, string|null> $header
     * @return array{pages:int, brand_name:int, remarks:int}
     */
    private function columnMap(array $header): array
    {
        $normalized = [];

        foreach ($header as $index => $column) {
            $key = strtolower(trim((string) $column));
            $key = str_replace([' ', '-'], '_', $key);
            $normalized[$key] = $index;
        }

        foreach (['pages', 'brand_name', 'remarks'] as $required) {
            if (! array_key_exists($required, $normalized)) {
                throw new RuntimeException("Catalog CSV is missing required column: {$required}");
            }
        }

        return [
            'pages' => $normalized['pages'],
            'brand_name' => $normalized['brand_name'],
            'remarks' => $normalized['remarks'],
        ];
    }

    /**
     * @param array<int, string|null> $row
     * @param array{pages:int, brand_name:int, remarks:int} $columns
     */
    private function value(array $row, array $columns, string $column): string
    {
        return trim((string) ($row[$columns[$column]] ?? ''));
    }

    private function skuFromRemarks(string $remarks): string
    {
        $sku = strtoupper($remarks);
        $sku = preg_replace('/[^A-Z0-9]+/', '-', $sku) ?? $sku;
        $sku = trim($sku, '-');

        if ($sku === '') {
            $sku = 'CATALOG-' . substr(sha1($remarks), 0, 8);
        }

        if (strlen($sku) > 90) {
            $sku = substr($sku, 0, 81) . '-' . substr(sha1($remarks), 0, 8);
        }

        return $sku;
    }
}

class RollbackCatalogImport extends RuntimeException
{
}
