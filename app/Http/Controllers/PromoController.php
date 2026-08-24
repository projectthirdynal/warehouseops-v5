<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Promo\Enums\PromoType;
use App\Domain\Promo\Models\Promo;
use App\Http\Requests\PromoRequest;
use App\Services\PromoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PromoController extends Controller
{
    public function __construct(
        private PromoService $promoService,
    ) {}

    public function index(Request $request): Response
    {
        $query = Promo::with(['product', 'variant', 'freeProduct', 'creator'])
            ->orderByDesc('created_at');

        if ($request->has('search') && $request->string('search')->isNotEmpty()) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                    ->orWhereRaw('LOWER(promo_code) LIKE ?', ['%'.mb_strtolower($search).'%']);
            });
        }

        if ($request->boolean('active_only')) {
            $query->active();
        }

        $promos = $query->paginate(15)->withQueryString();

        return Inertia::render('Telesales/Promos/Index', [
            'promos' => $promos,
            'filters' => $request->only(['search', 'active_only']),
            'promoTypes' => collect(PromoType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'description' => $t->description(),
            ])->toArray(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Telesales/Promos/Create', [
            'promoTypes' => collect(PromoType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'description' => $t->description(),
            ])->toArray(),
        ]);
    }

    public function store(PromoRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['promo_code'] = $validated['promo_code'] ?? strtoupper(str()->random(8));
        $validated['created_by'] = auth()->id();

        $promo = Promo::create($validated);

        return response()->json([
            'message' => 'Promo created successfully',
            'promo' => $promo,
        ], 201);
    }

    public function update(PromoRequest $request, Promo $promo): JsonResponse
    {
        $promo->update($request->validated());

        return response()->json([
            'message' => 'Promo updated successfully',
            'promo' => $promo->fresh(['product', 'variant', 'freeProduct']),
        ]);
    }

    public function destroy(Promo $promo): JsonResponse
    {
        $promo->delete();

        return response()->json(['message' => 'Promo deleted']);
    }

    public function toggleActive(Promo $promo): JsonResponse
    {
        $promo->update(['is_active' => ! $promo->is_active]);

        return response()->json([
            'message' => $promo->is_active ? 'Promo activated' : 'Promo deactivated',
            'is_active' => $promo->is_active,
        ]);
    }

    /**
     * API: Get active promos for a given product (used by agent order form).
     */
    public function activeForProduct(Request $request): JsonResponse
    {
        $productId = $request->integer('product_id');

        $promos = $this->promoService->getActivePromosForProduct($productId);

        return response()->json([
            'promos' => $promos->map(fn ($p) => [
                'id' => $p->id,
                'promo_code' => $p->promo_code,
                'name' => $p->name,
                'type' => $p->type->value,
                'type_label' => $p->type->label(),
                'summary' => $p->summary,
                'trigger_quantity' => $p->trigger_quantity,
                'free_quantity' => $p->free_quantity,
                'discount_percentage' => (float) $p->discount_percentage,
                'free_item_name' => $p->free_item_name,
                'description' => $p->description,
            ]),
        ]);
    }

    /**
     * API: Preview the effect of selected promos on a given quantity + price.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'promo_ids' => ['nullable', 'array'],
            'promo_ids.*' => ['integer'],
        ]);

        $result = $this->promoService->previewPromos(
            $validated['product_id'] ?? null,
            $validated['quantity'],
            (float) $validated['unit_price'],
            $validated['promo_ids'] ?? [],
        );

        return response()->json($result);
    }
}
