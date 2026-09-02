<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Product\Models;

use Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-####'),
            'name' => fake()->words(3, true),
            'brand' => fake()->company(),
            'category' => fake()->randomElement(['Electronics', 'Home', 'Fashion', 'Sports']),
            'selling_price' => fake()->randomFloat(2, 100, 5000),
            'cost_price' => fake()->randomFloat(2, 50, 3000),
            'weight_grams' => fake()->numberBetween(50, 5000),
            'description' => fake()->sentence(),
            'image_url' => null,
            'is_active' => true,
            'requires_qa' => false,
        ];
    }

    public function withBarcode(string $barcode): static
    {
        return $this->state(fn (array $attributes) => [
            'barcode' => $barcode,
        ]);
    }

    public function withQrCode(string $qrCode): static
    {
        return $this->state(fn (array $attributes) => [
            'qr_code' => $qrCode,
        ]);
    }
}
