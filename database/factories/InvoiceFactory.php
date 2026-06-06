<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\ThirdParty;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'ref' => 'INV-' . now()->year . '-' . str_pad(fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'type' => 'standard',
            'status' => 'DRAFT',
            'client_name' => fake()->company(),
            'date_invoice' => now(),
            'currency' => 'PHP',
            'subtotal' => 0,
            'discount_amount' => 0,
            'tax_rate' => 12,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'amount_paid' => 0,
            'amount_due' => 0,
            'created_by' => User::factory(),
        ];
    }

    public function withThirdParty(): static
    {
        return $this->state(fn (array $attributes) => [
            'third_party_id' => ThirdParty::factory(),
        ]);
    }

    public function validated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'VALIDATED',
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PAID',
        ]);
    }
}
