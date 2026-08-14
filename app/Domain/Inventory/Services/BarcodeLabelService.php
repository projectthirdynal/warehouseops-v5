<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\Supply;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use App\Models\SiteSetting;

class BarcodeLabelService
{
    public const FORMAT_CODE128 = 'CODE128';

    public const FORMAT_EAN13 = 'EAN13';

    public const FORMAT_QR = 'QR';

    public const FORMATS = [
        self::FORMAT_CODE128 => 'Code 128',
        self::FORMAT_EAN13 => 'EAN-13',
        self::FORMAT_QR => 'QR Code',
    ];

    public const LABEL_SIZES = [
        'small' => ['width' => 50, 'height' => 30, 'cols' => 4, 'label' => '50×30mm (4-col)'],
        'medium' => ['width' => 100, 'height' => 50, 'cols' => 2, 'label' => '100×50mm (2-col)'],
        'large' => ['width' => 100, 'height' => 70, 'cols' => 2, 'label' => '100×70mm (2-col)'],
    ];

    /**
     * Get label settings.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return [
            'format' => (string) SiteSetting::get('barcode_format', self::FORMAT_CODE128),
            'label_size' => (string) SiteSetting::get('barcode_label_size', 'medium'),
            'include_name' => (bool) SiteSetting::get('barcode_include_name', true),
            'include_sku' => (bool) SiteSetting::get('barcode_include_sku', true),
            'include_price' => (bool) SiteSetting::get('barcode_include_price', false),
            'include_barcode_text' => (bool) SiteSetting::get('barcode_include_text', true),
            'copies' => (int) SiteSetting::get('barcode_copies', 1),
            'auto_generate' => (bool) SiteSetting::get('barcode_auto_generate', true),
        ];
    }

    /**
     * Update settings.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(array $data): void
    {
        $keys = [
            'format' => 'barcode_format',
            'label_size' => 'barcode_label_size',
            'include_name' => 'barcode_include_name',
            'include_sku' => 'barcode_include_sku',
            'include_price' => 'barcode_include_price',
            'include_barcode_text' => 'barcode_include_text',
            'copies' => 'barcode_copies',
            'auto_generate' => 'barcode_auto_generate',
        ];

        foreach ($keys as $field => $settingKey) {
            if (array_key_exists($field, $data)) {
                SiteSetting::set($settingKey, $data[$field]);
            }
        }
    }

    /**
     * Get items for label generation.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getItems(array $filters = []): array
    {
        $itemType = $filters['item_type'] ?? 'all';
        $search = $filters['search'] ?? '';
        $onlyWithoutBarcode = (bool) ($filters['without_barcode'] ?? false);
        $limit = (int) ($filters['limit'] ?? 100);

        $items = collect();

        if ($itemType === 'all' || $itemType === 'product') {
            $products = Product::query()
                ->when($search, function ($q, string $s): void {
                    $q->where(function ($inner) use ($s): void {
                        $inner->where('sku', 'like', "%{$s}%")
                            ->orWhere('name', 'like', "%{$s}%")
                            ->orWhere('barcode', 'like', "%{$s}%");
                    });
                })
                ->when($onlyWithoutBarcode, fn ($q) => $q->whereNull('barcode')->orWhere('barcode', ''))
                ->where('is_active', true)
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'sku', 'name', 'barcode', 'qr_code', 'selling_price', 'brand', 'category']);

            foreach ($products as $product) {
                $items->push([
                    'type' => 'product',
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'barcode' => $product->barcode,
                    'qr_code' => $product->qr_code,
                    'price' => (float) $product->selling_price,
                    'brand' => $product->brand,
                    'category' => $product->category,
                    'label_text' => $product->barcode ?? $product->sku,
                ]);
            }
        }

        if ($itemType === 'all' || $itemType === 'variant') {
            $variants = ProductVariant::query()
                ->with('product:id,sku,name,barcode')
                ->when($search, function ($q, string $s): void {
                    $q->whereHas('product', function ($pq) use ($s): void {
                        $pq->where('sku', 'like', "%{$s}%")
                            ->orWhere('name', 'like', "%{$s}%");
                    })->orWhere('sku', 'like', "%{$s}%")
                        ->orWhere('variant_name', 'like', "%{$s}%");
                })
                ->where('is_active', true)
                ->orderBy('sku')
                ->limit($limit)
                ->get(['id', 'product_id', 'sku', 'variant_name', 'selling_price']);

            foreach ($variants as $variant) {
                $items->push([
                    'type' => 'variant',
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->product?->name.' — '.$variant->variant_name,
                    'barcode' => $variant->product?->barcode,
                    'qr_code' => null,
                    'price' => (float) ($variant->selling_price ?? $variant->product?->selling_price ?? 0),
                    'brand' => $variant->product?->name,
                    'category' => 'variant',
                    'label_text' => $variant->sku,
                ]);
            }
        }

        if ($itemType === 'all' || $itemType === 'supply') {
            $supplies = Supply::query()
                ->when($search, function ($q, string $s): void {
                    $q->where('sku', 'like', "%{$s}%")
                        ->orWhere('name', 'like', "%{$s}%");
                })
                ->where('is_active', true)
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'sku', 'name', 'cost_price', 'category']);

            foreach ($supplies as $supply) {
                $items->push([
                    'type' => 'supply',
                    'id' => $supply->id,
                    'sku' => $supply->sku,
                    'name' => $supply->name,
                    'barcode' => $supply->sku,
                    'qr_code' => null,
                    'price' => (float) $supply->cost_price,
                    'brand' => null,
                    'category' => $supply->category,
                    'label_text' => $supply->sku,
                ]);
            }
        }

        return $items->values()->all();
    }

    /**
     * Generate a barcode for a product.
     */
    public function generateBarcode(): string
    {
        $settings = $this->getSettings();
        $format = $settings['format'];

        return match ($format) {
            self::FORMAT_EAN13 => $this->generateEan13(),
            self::FORMAT_QR => $this->generateQr(),
            default => $this->generateCode128(),
        };
    }

    /**
     * Auto-generate barcodes for products without one.
     *
     * @return array<string, int>
     */
    public function autoGenerateBarcodes(): array
    {
        $products = Product::whereNull('barcode')
            ->orWhere('barcode', '')
            ->where('is_active', true)
            ->get();

        $count = 0;
        foreach ($products as $product) {
            $barcode = $this->generateBarcode();
            $product->barcode = $barcode;
            $product->saveQuietly();
            $count++;
        }

        return ['generated' => $count];
    }

    /**
     * Assign a specific barcode to a product.
     */
    public function assignBarcode(int $productId, string $barcode): Product
    {
        $product = Product::findOrFail($productId);
        $product->barcode = $barcode;
        $product->save();

        return $product->fresh();
    }

    /**
     * Generate label data for printing.
     *
     * @param  array<int, array<string, mixed>>  $selectedItems
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function generateLabels(array $selectedItems, ?array $settings = null): array
    {
        $settings ??= $this->getSettings();
        $labelSize = self::LABEL_SIZES[$settings['label_size']] ?? self::LABEL_SIZES['medium'];
        $copies = max(1, (int) $settings['copies']);

        $labels = [];
        foreach ($selectedItems as $item) {
            for ($i = 0; $i < $copies; $i++) {
                $labels[] = [
                    'type' => $item['type'],
                    'id' => $item['id'],
                    'sku' => $item['sku'],
                    'name' => $settings['include_name'] ? $item['name'] : null,
                    'barcode' => $item['barcode'] ?? $item['sku'],
                    'label_text' => $item['label_text'] ?? $item['barcode'] ?? $item['sku'],
                    'price' => $settings['include_price'] ? $item['price'] : null,
                    'show_sku' => $settings['include_sku'],
                    'show_name' => $settings['include_name'],
                    'show_price' => $settings['include_price'],
                    'show_text' => $settings['include_barcode_text'],
                    'format' => $settings['format'],
                ];
            }
        }

        return [
            'labels' => $labels,
            'count' => count($labels),
            'label_size' => $labelSize,
            'format' => $settings['format'],
        ];
    }

    /**
     * Get dashboard data.
     *
     * @return array<string, mixed>
     */
    public function getDashboard(): array
    {
        $settings = $this->getSettings();

        $totalProducts = Product::where('is_active', true)->count();
        $productsWithBarcode = Product::where('is_active', true)
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->count();
        $productsWithoutBarcode = $totalProducts - $productsWithBarcode;

        $totalSupplies = Supply::where('is_active', true)->count();
        $totalVariants = ProductVariant::where('is_active', true)->count();

        return [
            'summary' => [
                'total_products' => $totalProducts,
                'products_with_barcode' => $productsWithBarcode,
                'products_without_barcode' => $productsWithoutBarcode,
                'total_supplies' => $totalSupplies,
                'total_variants' => $totalVariants,
            ],
            'settings' => $settings,
            'formats' => self::FORMATS,
            'label_sizes' => self::LABEL_SIZES,
        ];
    }

    /**
     * Generate a Code128-compatible barcode.
     */
    private function generateCode128(): string
    {
        $prefix = 'WH';
        $timestamp = now()->format('ymd');
        $random = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return $prefix.$timestamp.$random;
    }

    /**
     * Generate an EAN-13 barcode with valid checksum.
     */
    private function generateEan13(): string
    {
        // Start with country code 200 (internal use range)
        $base = '200'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

        // Calculate checksum
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base[$i];
            $sum += $i % 2 === 0 ? $digit : $digit * 3;
        }
        $checksum = (10 - ($sum % 10)) % 10;

        return $base.$checksum;
    }

    /**
     * Generate a QR code identifier.
     */
    private function generateQr(): string
    {
        return 'QR-'.strtoupper(uniqid());
    }
}
