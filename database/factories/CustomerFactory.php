<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = \App\Models\Customer::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'phone' => '09' . $this->faker->numberBetween(100000000, 999999999),
            'normalized_phone' => '09' . $this->faker->numberBetween(100000000, 999999999),
            'risk_level' => 'LOW',
        ];
    }
}
