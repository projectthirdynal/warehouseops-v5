<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Services\BarcodeLabelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BarcodeLabelController extends Controller
{
    public function __construct(
        private readonly BarcodeLabelService $service
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()?->role, [
                'superadmin', 'admin', 'supervisor', 'warehouse', 'procurement',
            ])) {
                abort(403, 'Access denied');
            }

            return $next($request);
        });
    }

    public function index(): Response
    {
        return Inertia::render('Inventory/BarcodeLabels', [
            'dashboard' => $this->service->getDashboard(),
        ]);
    }

    public function api(Request $request): JsonResponse
    {
        $filters = $request->only(['item_type', 'search', 'without_barcode', 'limit']);

        return response()->json([
            'dashboard' => $this->service->getDashboard(),
            'items' => $this->service->getItems($filters),
        ]);
    }

    public function items(Request $request): JsonResponse
    {
        $filters = $request->only(['item_type', 'search', 'without_barcode', 'limit']);

        return response()->json([
            'items' => $this->service->getItems($filters),
        ]);
    }

    public function generateLabels(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'string', 'in:product,variant,supply'],
            'items.*.id' => ['required', 'integer'],
            'items.*.sku' => ['required', 'string'],
            'items.*.name' => ['nullable', 'string'],
            'items.*.barcode' => ['nullable', 'string'],
            'items.*.price' => ['nullable', 'numeric'],
        ]);

        $labels = $this->service->generateLabels($data['items']);

        return response()->json($labels);
    }

    public function autoGenerate(): RedirectResponse
    {
        $result = $this->service->autoGenerateBarcodes();

        return back()->with('success', "Generated {$result['generated']} barcodes for products without one.");
    }

    public function apiAutoGenerate(): JsonResponse
    {
        $result = $this->service->autoGenerateBarcodes();

        return response()->json($result);
    }

    public function assignBarcode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'barcode' => ['required', 'string', 'max:60', 'unique:products,barcode,'.$request->input('product_id')],
        ]);

        $this->service->assignBarcode((int) $data['product_id'], $data['barcode']);

        return back()->with('success', 'Barcode assigned.');
    }

    public function apiAssignBarcode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'barcode' => ['required', 'string', 'max:60', 'unique:products,barcode,'.$request->input('product_id')],
        ]);

        $product = $this->service->assignBarcode((int) $data['product_id'], $data['barcode']);

        return response()->json(['ok' => true, 'product' => $product->only(['id', 'sku', 'name', 'barcode'])]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'format' => ['required', 'string', 'in:CODE128,EAN13,QR'],
            'label_size' => ['required', 'string', 'in:small,medium,large'],
            'include_name' => ['boolean'],
            'include_sku' => ['boolean'],
            'include_price' => ['boolean'],
            'include_barcode_text' => ['boolean'],
            'copies' => ['required', 'integer', 'min:1', 'max:10'],
            'auto_generate' => ['boolean'],
        ]);

        $this->service->updateSettings($data);

        return back()->with('success', 'Barcode label settings updated.');
    }

    public function apiUpdateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'format' => ['required', 'string', 'in:CODE128,EAN13,QR'],
            'label_size' => ['required', 'string', 'in:small,medium,large'],
            'include_name' => ['boolean'],
            'include_sku' => ['boolean'],
            'include_price' => ['boolean'],
            'include_barcode_text' => ['boolean'],
            'copies' => ['required', 'integer', 'min:1', 'max:10'],
            'auto_generate' => ['boolean'],
        ]);

        $this->service->updateSettings($data);

        return response()->json(['ok' => true, 'settings' => $this->service->getSettings()]);
    }
}
