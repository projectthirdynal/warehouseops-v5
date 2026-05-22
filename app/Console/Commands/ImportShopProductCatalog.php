<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shop\Services\ProductCatalogImportService;
use Illuminate\Console\Command;

class ImportShopProductCatalog extends Command
{
    protected $signature = 'shop:import-product-catalog
        {path : CSV path with PAGES, BRAND NAME, and REMARKS columns}
        {--dry-run : Parse and validate without saving changes}';

    protected $description = 'Import Auto Encode page-name/SKU catalog into WarehouseOps products and Shop page mappings.';

    public function handle(ProductCatalogImportService $importer): int
    {
        $summary = $importer->importCsv(
            path: (string) $this->argument('path'),
            dryRun: (bool) $this->option('dry-run')
        );

        $this->components->info(sprintf(
            'Catalog %s: %d rows, %d products, %d page mappings, %d skipped.',
            $this->option('dry-run') ? 'validated' : 'imported',
            $summary['rows'],
            $summary['products'],
            $summary['mappings'],
            $summary['skipped'],
        ));

        return self::SUCCESS;
    }
}
