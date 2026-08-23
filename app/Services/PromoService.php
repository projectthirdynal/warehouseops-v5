<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Order\Models\Order;
use App\Domain\Promo\Enums\PromoType;
use App\Domain\Promo\Models\Promo;
use App\Domain\Shop\Models\ShopOrderItem;
use Illuminate\Support\Collection;

class PromoService
{
    /**
     * Get active promos applicable to a given product.
     *
     * @return Collection<int, Promo>
     */
    public function getActivePromosForProduct(?int $productId): Collection
    {
        return Promo::active()
            ->forProduct($productId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Apply selected promos to an order, creating ShopOrderItem line items for freebies
     * and calculating the discount total.
     *
     * @param  int  $quantity  The main product quantity ordered
     * @param  float  $unitPrice  The main product unit price
     * @param  array<int>  $promoIds  Selected promo IDs
     * @return array{discount_amount: float, free_items: list<array<string, mixed>>}
     */
    public function applyPromos(Order $order, int $quantity, float $unitPrice, array $promoIds): array
    {
        $discountAmount = 0.0;
        $freeItems = [];

        if (empty($promoIds)) {
            return ['discount_amount' => 0.0, 'free_items' => []];
        }

        $promos = Promo::active()
            ->whereIn('id', $promoIds)
            ->forProduct($order->product_id)
            ->get();

        foreach ($promos as $promo) {
            $result = $this->applySinglePromo($promo, $quantity, $unitPrice);

            if ($result['discount_amount'] > 0) {
                $discountAmount += $result['discount_amount'];
            }

            foreach ($result['free_items'] as $freeItem) {
                $freeItems[] = $freeItem;

                ShopOrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $freeItem['product_id'] ?? null,
                    'variant_id' => $freeItem['variant_id'] ?? null,
                    'sku' => $freeItem['sku'] ?? null,
                    'product_name' => $freeItem['name'],
                    'quantity' => $freeItem['quantity'],
                    'unit_price' => 0, // Free
                    'discount_amount' => 0,
                    'line_total' => 0, // Free
                    'metadata' => [
                        'promo_id' => $promo->id,
                        'promo_code' => $promo->promo_code,
                        'type' => 'FREEBIE',
                    ],
                ]);
            }
        }

        // Update order totals with discount
        if ($discountAmount > 0) {
            $newTotal = max(0, ($order->total_amount - $discountAmount));
            $order->update([
                'discount_amount' => $discountAmount,
                'total_amount' => $newTotal,
                'cod_amount' => $newTotal,
            ]);
        }

        return ['discount_amount' => $discountAmount, 'free_items' => $freeItems];
    }

    /**
     * Calculate the effect of a single promo on a given quantity + price.
     *
     * @return array{discount_amount: float, free_items: list<array<string, mixed>>}
     */
    public function calculatePromoEffect(Promo $promo, int $quantity, float $unitPrice): array
    {
        return $this->applySinglePromo($promo, $quantity, $unitPrice);
    }

    /**
     * Preview the total effect of multiple promos without creating any records.
     *
     * @param  array<int>  $promoIds
     * @return array{discount_amount: float, free_items: list<array<string, mixed>>, total: float}
     */
    public function previewPromos(?int $productId, int $quantity, float $unitPrice, array $promoIds): array
    {
        if (empty($promoIds)) {
            $subtotal = $quantity * $unitPrice;

            return ['discount_amount' => 0.0, 'free_items' => [], 'total' => $subtotal];
        }

        $promos = Promo::active()
            ->whereIn('id', $promoIds)
            ->forProduct($productId)
            ->get();

        $totalDiscount = 0.0;
        $allFreeItems = [];

        foreach ($promos as $promo) {
            $result = $this->applySinglePromo($promo, $quantity, $unitPrice);
            $totalDiscount += $result['discount_amount'];
            array_push($allFreeItems, ...$result['free_items']);
        }

        $subtotal = $quantity * $unitPrice;
        $total = max(0, $subtotal - $totalDiscount);

        return [
            'discount_amount' => $totalDiscount,
            'free_items' => $allFreeItems,
            'total' => $total,
        ];
    }

    /**
     * @return array{discount_amount: float, free_items: list<array<string, mixed>>}
     */
    private function applySinglePromo(Promo $promo, int $quantity, float $unitPrice): array
    {
        return match ($promo->type) {
            PromoType::FREEBIE => $this->applyFreebie($promo),
            PromoType::BUNDLE => $this->applyBundle($promo, $quantity, $unitPrice),
            PromoType::DISCOUNT => $this->applyDiscount($promo, $quantity, $unitPrice),
        };
    }

    /**
     * @return array{discount_amount: float, free_items: list<array<string, mixed>>}
     */
    private function applyFreebie(Promo $promo): array
    {
        $freeName = $promo->free_item_name
            ?? $promo->freeProduct?->name
            ?? 'Free Item';

        return [
            'discount_amount' => 0.0,
            'free_items' => [[
                'name' => $freeName,
                'quantity' => max(1, $promo->free_quantity),
                'product_id' => $promo->free_product_id,
                'variant_id' => $promo->free_variant_id,
                'sku' => $promo->freeProduct?->sku,
                'unit_price' => 0,
            ]],
        ];
    }

    /**
     * @return array{discount_amount: float, free_items: list<array<string, mixed>>}
     */
    private function applyBundle(Promo $promo, int $quantity, float $unitPrice): array
    {
        // Buy X Take Y: for every X units ordered, customer gets Y free units of same product
        $triggerQty = max(1, $promo->trigger_quantity);
        $freeQty = max(0, $promo->free_quantity);

        if ($freeQty === 0) {
            return ['discount_amount' => 0.0, 'free_items' => []];
        }

        $bundleCount = intdiv($quantity, $triggerQty);
        $totalFree = $bundleCount * $freeQty;

        if ($totalFree === 0) {
            return ['discount_amount' => 0.0, 'free_items' => []];
        }

        $discountAmount = $totalFree * $unitPrice;

        return [
            'discount_amount' => $discountAmount,
            'free_items' => [[
                'name' => $promo->product?->name ?? 'Bundle Free Item',
                'quantity' => $totalFree,
                'product_id' => $promo->product_id,
                'variant_id' => $promo->variant_id,
                'sku' => $promo->product?->sku,
                'unit_price' => 0,
            ]],
        ];
    }

    /**
     * @return array{discount_amount: float, free_items: list<array<string, mixed>>}
     */
    private function applyDiscount(Promo $promo, int $quantity, float $unitPrice): array
    {
        $subtotal = $quantity * $unitPrice;
        $discountAmount = $subtotal * ((float) $promo->discount_percentage / 100);

        return [
            'discount_amount' => round($discountAmount, 2),
            'free_items' => [],
        ];
    }
}
