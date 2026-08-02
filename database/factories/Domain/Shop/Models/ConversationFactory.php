<?php

namespace Database\Factories\Domain\Shop\Models;

use App\Domain\Shop\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'channel' => 'messenger',
            'status' => 'new',
            'thread_key' => 'facebook:'.$this->faker->unique()->numberBetween(100000, 999999).':'.$this->faker->unique()->numberBetween(100000, 999999),
            'last_message_preview' => $this->faker->sentence,
            'last_message_at' => now(),
            'unread_count' => 0,
        ];
    }
}
