<?php

namespace Database\Factories\Domain\Shop\Models;

use Modules\Shop\Models\FacebookPage;
use Illuminate\Database\Eloquent\Factories\Factory;

class FacebookPageFactory extends Factory
{
    protected $model = FacebookPage::class;

    public function definition(): array
    {
        return [
            'page_id' => (string) $this->faker->unique()->numberBetween(100000000000, 999999999999),
            'page_name' => $this->faker->company,
            'page_access_token' => 'test-page-token-'.$this->faker->uuid,
            'connected_status' => 'connected',
            'connection_status' => 'active',
            'webhook_status' => 'subscribed',
        ];
    }
}
