<?php

namespace App\Http\Controllers;

use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use App\Domain\Product\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

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
            'low_stock'  => $this->inventory->getLowStockProducts()->count(),
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

        $product = Product::create($validated);

        // Create variants
        if (!empty($validated['variants'])) {
            foreach ($validated['variants'] as $variantData) {
                $product->variants()->create($variantData);
            }
        }

        // Set initial stock
        $initialStock = $validated['initial_stock'] ?? 0;
        if ($initialStock > 0) {
            $this->inventory->stockIn(
                $product->id,
                $initialStock,
                notes: 'Initial stock on product creation',
                performedBy: auth()->id(),
            );
        }

        // Set reorder point
        if (isset($validated['reorder_point'])) {
            $product->stock()->updateOrCreate(
                ['variant_id' => null],
                ['reorder_point' => $validated['reorder_point']]
            );
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
            'variant_id' => ['nullable', 'exists:product_variants,id'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        match ($validated['type']) {
            'stock_in' => $this->inventory->stockIn(
                $product->id,
                $validated['quantity'],
                $validated['variant_id'] ?? null,
                $validated['notes'],
                auth()->id(),
            ),
            'stock_out' => $this->inventory->stockOut(
                $product->id,
                $validated['quantity'],
                $validated['variant_id'] ?? null,
                $validated['notes'],
                auth()->id(),
            ),
            'adjustment' => $this->inventory->adjustStock(
                $product->id,
                $validated['quantity'],
                $validated['variant_id'] ?? null,
                $validated['notes'],
                auth()->id(),
            ),
        };

        return back()->with('success', 'Stock updated successfully.');
    }
}
