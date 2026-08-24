<?php

namespace Database\Factories;

use App\Models\Waybill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Waybill>
 */
class WaybillFactory extends Factory
{
    protected $model = Waybill::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'waybill_number' => 'JT'.fake()->unique()->numerify('##########'),
            'status' => 'DELIVERED',
            'receiver_name' => fake()->name(),
            'receiver_phone' => '09'.fake()->numerify('#########'),
            'receiver_address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'barangay' => fake()->word(),
            'item_name' => fake()->words(3, true),
            'item_qty' => 1,
            'amount' => fake()->randomFloat(2, 100, 5000),
            'courier_provider' => 'JNT',
            'delivered_at' => now()->subDays(10),
        ];
    }
}
