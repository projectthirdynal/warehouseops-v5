<?php

namespace Database\Factories\Domain\Inventory\Models;

use Modules\Inventory\Models\Supply;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplyFactory extends Factory
{
    protected $model = Supply::class;

    public function definition(): array
    {
        return [
            'sku' => strtoupper($this->faker->unique()->bothify('MAT-###??')),
            'name' => $this->faker->words(3, true),
            'category' => $this->faker->randomElement(['Packaging', 'Cleaning', 'Office']),
            'section' => $this->faker->randomElement(['STOCK', 'OPEX']),
            'stock_status' => 'MOVING',
            'cost_price' => $this->faker->randomFloat(2, 10, 5000),
            'reorder_point' => 10,
            'is_active' => true,
        ];
    }
}
