<?php

namespace Database\Factories\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Factories\Factory;

class FacebookAccountFactory extends Factory
{
    protected $model = \App\Domain\Shop\Models\FacebookAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'facebook_user_id' => (string) $this->faker->unique()->numberBetween(100000000000, 999999999999),
            'facebook_user_name' => $this->faker->name,
            'email' => $this->faker->safeEmail,
            'access_token' => 'test-account-token-' . $this->faker->uuid,
            'status' => 'connected',
            'connection_status' => 'active',
            'connected_at' => now(),
        ];
    }
}
