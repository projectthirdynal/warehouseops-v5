<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryScannerController extends Controller
{
    public function __construct(
        private readonly StockService $stockService,
    ) {}

    /**
     * Look up a product by barcode, QR code, or SKU.
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'barcode' => 'required|string|max:120',
        ]);

        $barcode = trim($data['barcode']);

        // Deterministic lookup: barcode exact match → QR code exact match → SKU exact match
        $product = Product::where('barcode', $barcode)
            ->select(['id', 'sku', 'name', 'barcode', 'qr_code', 'selling_price', 'cost_price'])
            ->first();

        if (! $product) {
            $product = Product::where('qr_code', $barcode)
                ->select(['id', 'sku', 'name', 'barcode', 'qr_code', 'selling_price', 'cost_price'])
                ->first();
        }

        if (! $product) {
            $product = Product::where('sku', $barcode)
                ->select(['id', 'sku', 'name', 'barcode', 'qr_code', 'selling_price', 'cost_price'])
                ->first();
        }

        if (! $product) {
            return response()->json([
                'status' => 'not_found',
                'barcode' => $barcode,
                'message' => 'Product not found. Scan barcode, QR code, or SKU.',
            ], 404);
        }

        $stocks = ProductStock::where('product_id', $product->id)
            ->whereNull('variant_id')
            ->with('warehouse:id,name,code')
            ->get();

        $defaultWarehouse = Warehouse::default();

        $stockData = $stocks->isNotEmpty()
            ? $stocks->map(fn ($s) => [
                'warehouse_id' => $s->warehouse_id,
                'warehouse_name' => $s->warehouse?->name ?? 'Default',
                'current_stock' => $s->current_stock,
                'available_stock' => $s->available_stock,
                'reorder_point' => $s->reorder_point,
                'is_low_stock' => $s->is_low_stock,
            ])->toArray()
            : [[
                'warehouse_id' => $defaultWarehouse?->id,
                'warehouse_name' => $defaultWarehouse?->name ?? 'Default',
                'current_stock' => 0,
                'available_stock' => 0,
                'reorder_point' => 0,
                'is_low_stock' => false,
            ]];

        return response()->json([
            'status' => 'found',
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'qr_code' => $product->qr_code,
                'selling_price' => $product->selling_price,
                'cost_price' => $product->cost_price,
            ],
            'stocks' => $stockData,
            'audio' => 'success',
        ]);
    }

    /**
     * Submit a quick stock adjustment from scanner.
     * Creates a PENDING adjustment (requires supervisor approval).
     */
    public function quickAdjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity_after' => 'required|integer|min:0|max:999999',
            'reason_code' => 'required|string|max:50',
            'reason_notes' => 'nullable|string|max:500',
        ]);

        $productId = (int) $data['product_id'];
        $warehouseId = (int) $data['warehouse_id'];
        $quantityAfter = (int) $data['quantity_after'];

        $adjustmentId = DB::transaction(function () use ($request, $productId, $warehouseId, $quantityAfter, $data) {
            $currentQty = (int) (ProductStock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->whereNull('variant_id')
                ->lockForUpdate()
                ->value('current_stock') ?? 0);

            return DB::table('stock_adjustments')->insertGetId([
                'product_id' => $productId,
                'variant_id' => null,
                'warehouse_id' => $warehouseId,
                'reason_code' => $data['reason_code'],
                'reason_notes' => $data['reason_notes'] ?? 'Quick scan adjustment',
                'quantity_before' => $currentQty,
                'quantity_after' => $quantityAfter,
                'variance' => $quantityAfter - $currentQty,
                'status' => 'PENDING',
                'submitted_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'submitted',
            'adjustment_id' => $adjustmentId,
            'message' => 'Stock adjustment submitted for approval.',
        ]);
    }

    /**
     * Auto-approve a quick adjustment (for supervisors/admins only).
     */
    public function autoAdjust(Request $request): JsonResponse
    {
        $role = $request->user()?->role;
        if (! in_array($role, ['superadmin', 'admin', 'supervisor'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity_after' => 'required|integer|min:0|max:999999',
            'reason_code' => 'required|string|max:50',
            'reason_notes' => 'nullable|string|max:500',
        ]);

        $productId = (int) $data['product_id'];
        $warehouseId = (int) $data['warehouse_id'];
        $quantityAfter = (int) $data['quantity_after'];

        $this->stockService->adjustStock(
            productId: $productId,
            variantId: null,
            warehouseId: $warehouseId,
            locationId: null,
            newQuantity: $quantityAfter,
            notes: $data['reason_notes'] ?? "Quick scan: {$data['reason_code']}",
            performedBy: $request->user()->id,
        );

        return response()->json([
            'status' => 'approved',
            'message' => 'Stock adjusted successfully.',
        ]);
    }
}
