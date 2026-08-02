<?php

namespace Database\Factories\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Factories\Factory;

class FacebookWebhookEventFactory extends Factory
{
    protected $model = \App\Domain\Shop\Models\FacebookWebhookEvent::class;

    public function definition(): array
    {
        return [
            'facebook_page_id' => null,
            'event_id' => $this->faker->uuid,
            'event_key' => $this->faker->uuid,
            'object' => 'page',
            'event_type' => 'messaging',
            'sender_psid' => (string) $this->faker->numberBetween(100000000000, 999999999999),
            'recipient_id' => (string) $this->faker->numberBetween(100000000000, 999999999999),
            'payload' => [],
            'signature_valid' => true,
            'status' => 'received',
        ];
    }
}
