<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Promo\Models;

use App\Domain\Promo\Enums\PromoType;
use App\Domain\Promo\Models\Promo;
use Illuminate\Database\Eloquent\Factories\Factory;

class PromoFactory extends Factory
{
    protected $model = Promo::class;

    public function definition(): array
    {
        return [
            'promo_code' => strtoupper($this->faker->unique()->lexify('????????')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'type' => $this->faker->randomElement(PromoType::cases()),
            'trigger_quantity' => 1,
            'free_quantity' => 1,
            'free_item_name' => $this->faker->words(2, true),
            'discount_percentage' => 10,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }
}
