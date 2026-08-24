<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Shop\Models;

use App\Domain\Shop\Models\AddressMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

class AddressMappingFactory extends Factory
{
    protected $model = AddressMapping::class;

    public function definition(): array
    {
        return [
            'province' => $this->faker->state(),
            'city_municipality' => $this->faker->city(),
            'barangay' => $this->faker->word(),
            'island_group' => 'Luzon',
            'business_region' => 'NCR',
            'courier_zone' => 'NCR',
        ];
    }
}
