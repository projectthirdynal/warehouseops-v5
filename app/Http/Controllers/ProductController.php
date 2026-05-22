<?php

namespace App\Http\Controllers;

use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

class ProductController extends Controller
{
    public function __construct(private StockService $stockService) {}

    public function index(Request $request)
    {
        $query = Product::with(['stock', 'activeVariants.stock']);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->input('status') === 'active') {
            $query->active();
        } elseif ($request->input('status') === 'inactive') {
            $query->where('is_active', false);
        }

        if ($request->input('stock') === 'low') {
            $query->whereHas('stock', function ($q) {
                $q->whereRaw('(current_stock - reserved_stock) <= reorder_point');
            });
        }

        $products = $query->orderBy('name')->paginate(20)->withQueryString();

        $stats = [
            'total'      => Product::count(),
            'active'     => Product::where('is_active', true)->count(),
            'low_stock'  => ProductStock::whereRaw('(current_stock - reserved_stock) <= reorder_point')->count(),
            'categories' => Product::distinct()->pluck('category')->filter()->values(),
        ];

        return Inertia::render('Products/Index', [
            'products' => $products,
            'stats'    => $stats,
            'filters'  => $request->only(['search', 'category', 'status', 'stock']),
        ]);
    }

    public function create()
    {
        $categories = Product::distinct()->pluck('category')->filter()->values();
        $brands = Product::distinct()->pluck('brand')->filter()->values();

        return Inertia::render('Products/Create', [
            'categories' => $categories,
            'brands'     => $brands,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sku'            => ['required', 'string', 'max:50', 'unique:products,sku'],
            'name'           => ['required', 'string', 'max:255'],
            'brand'          => ['nullable', 'string', 'max:100'],
            'category'       => ['nullable', 'string', 'max:100'],
            'selling_price'  => ['required', 'numeric', 'min:0'],
            'cost_price'     => ['required', 'numeric', 'min:0'],
            'weight_grams'   => ['required', 'integer', 'min:0'],
            'barcode'        => ['nullable', 'string', 'max:60'],
            'qr_code'        => ['nullable', 'string', 'max:120'],
            'min_stock_level' => ['nullable', 'integer', 'min:0'],
            'max_stock_level' => ['nullable', 'integer', 'min:0'],
            'expiry_tracking' => ['boolean'],
            'description'    => ['nullable', 'string'],
            'is_active'      => ['boolean'],
            'requires_qa'    => ['boolean'],
            'initial_stock'  => ['nullable', 'integer', 'min:0'],
            'reorder_point'  => ['nullable', 'integer', 'min:0'],
            'variants'       => ['nullable', 'array'],
            'variants.*.variant_name'   => ['required_with:variants', 'string', 'max:100'],
            'variants.*.sku'            => ['required_with:variants', 'string', 'max:50', 'distinct', 'unique:product_variants,sku'],
            'variants.*.selling_price'  => ['nullable', 'numeric', 'min:0'],
            'variants.*.cost_price'     => ['nullable', 'numeric', 'min:0'],
            'variants.*.weight_grams'   => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $product = DB::transaction(function () use ($validated) {
                $product = Product::create(Arr::except($validated, ['initial_stock', 'reorder_point', 'variants']));

                // Create variants
                if (!empty($validated['variants'])) {
                    foreach ($validated['variants'] as $variantData) {
                        $product->variants()->create($variantData);
                    }
                }

                $initialStock = $validated['initial_stock'] ?? 0;
                $warehouse = null;

                // Set initial stock
                if ($initialStock > 0) {
                    $warehouse = $this->defaultWarehouse();

                    $this->stockService->stockIn(
                        productId: (int) $product->id,
                        variantId: null,
                        warehouseId: (int) $warehouse->id,
                        locationId: null,
                        quantity: (int) $initialStock,
                        unitCost: (float) $product->cost_price,
                        performedBy: auth()->id(),
                    );
                }

                // Set reorder point
                if (isset($validated['reorder_point'])) {
                    $warehouse ??= $this->defaultWarehouse();

                    $stock = ProductStock::firstOrNew([
                        'product_id' => $product->id,
                        'variant_id' => null,
                        'warehouse_id' => $warehouse->id,
                    ]);

                    if (! $stock->exists) {
                        $stock->location_id = null;
                        $stock->current_stock = 0;
                        $stock->reserved_stock = 0;
                    }

                    $stock->reorder_point = $validated['reorder_point'];
                    $stock->save();
                }

                return $product;
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('products.index')->with('success', "Product '{$product->name}' created successfully.");
    }

    public function show(Product $product)
    {
        $product->load(['variants.stock', 'stock']);

        $movements = $product->movements()
            ->with('performer')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Products/Show', [
            'product'   => $product,
            'movements' => $movements,
        ]);
    }

    public function edit(Product $product)
    {
        $product->load('variants');
        $categories = Product::distinct()->pluck('category')->filter()->values();
        $brands = Product::distinct()->pluck('brand')->filter()->values();

        return Inertia::render('Products/Edit', [
            'product'    => $product,
            'categories' => $categories,
            'brands'     => $brands,
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'sku'            => ['required', 'string', 'max:50', 'unique:products,sku,' . $product->id],
            'name'           => ['required', 'string', 'max:255'],
            'brand'          => ['nullable', 'string', 'max:100'],
            'category'       => ['nullable', 'string', 'max:100'],
            'selling_price'  => ['required', 'numeric', 'min:0'],
            'cost_price'     => ['required', 'numeric', 'min:0'],
            'weight_grams'   => ['required', 'integer', 'min:0'],
            'barcode'        => ['nullable', 'string', 'max:60'],
            'qr_code'        => ['nullable', 'string', 'max:120'],
            'min_stock_level' => ['nullable', 'integer', 'min:0'],
            'max_stock_level' => ['nullable', 'integer', 'min:0'],
            'expiry_tracking' => ['boolean'],
            'description'    => ['nullable', 'string'],
            'is_active'      => ['boolean'],
            'requires_qa'    => ['boolean'],
        ]);

        $product->update($validated);

        return redirect()->route('products.show', $product)->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Product archived.');
    }

    /**
     * Stock adjustment (manual stock-in or correction).
     */
    public function adjustStock(Request $request, Product $product)
    {
        $validated = $request->validate([
            'type'       => ['required', 'in:stock_in,stock_out,adjustment'],
            'quantity'   => ['required', 'integer', 'min:1'],
            'variant_id' => [
                'nullable',
                Rule::exists('product_variants', 'id')->where('product_id', $product->id),
            ],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $warehouse = $this->defaultWarehouse();

            match ($validated['type']) {
                'stock_in' => $this->stockService->stockIn(
                    productId: (int) $product->id,
                    variantId: $validated['variant_id'] ?? null,
                    warehouseId: (int) $warehouse->id,
                    locationId: null,
                    quantity: (int) $validated['quantity'],
                    unitCost: (float) $product->cost_price,
                    performedBy: auth()->id(),
                ),
                'stock_out' => $this->stockService->stockOut(
                    productId: (int) $product->id,
                    variantId: $validated['variant_id'] ?? null,
                    warehouseId: (int) $warehouse->id,
                    quantity: (int) $validated['quantity'],
                    referenceType: 'manual_adjustment',
                    performedBy: auth()->id(),
                    notes: $validated['notes'] ?? null,
                ),
                'adjustment' => $this->stockService->adjustStock(
                    productId: (int) $product->id,
                    variantId: $validated['variant_id'] ?? null,
                    warehouseId: (int) $warehouse->id,
                    locationId: null,
                    newQuantity: (int) $validated['quantity'],
                    notes: $validated['notes'] ?? null,
                    performedBy: auth()->id(),
                ),
            };
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Stock updated successfully.');
    }

    private function defaultWarehouse(): Warehouse
    {
        $warehouse = Warehouse::where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if (! $warehouse) {
            throw new RuntimeException('Create an active warehouse before posting product stock.');
        }

        return $warehouse;
    }
}
