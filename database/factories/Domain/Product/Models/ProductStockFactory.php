<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Product\Models;

use Modules\Inventory\Models\Warehouse;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductStock>
 */
class ProductStockFactory extends Factory
{
    protected $model = ProductStock::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_id' => null,
            'warehouse_id' => Warehouse::factory(),
            'location_id' => null,
            'current_stock' => fake()->numberBetween(0, 1000),
            'reserved_stock' => 0,
            'reorder_point' => fake()->numberBetween(10, 50),
            'last_restock_at' => null,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
        ]);
    }

    public function forWarehouse(Warehouse $warehouse): static
    {
        return $this->state(fn (array $attributes) => [
            'warehouse_id' => $warehouse->id,
        ]);
    }

    public function withQuantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'current_stock' => $quantity,
        ]);
    }
}
