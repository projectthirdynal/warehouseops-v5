<?php

namespace Database\Seeders;

use App\Models\RecyclingRule;
use Illuminate\Database\Seeder;

class RecyclingRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['outcome' => 'NO_ANSWER', 'cooldown_hours' => 24, 'max_cycles' => 5, 'next_action' => 'RECYCLE', 'is_active' => true],
            ['outcome' => 'CALLBACK', 'cooldown_hours' => 0, 'max_cycles' => 999, 'next_action' => 'RECYCLE', 'is_active' => true],
            ['outcome' => 'INTERESTED', 'cooldown_hours' => 48, 'max_cycles' => 3, 'next_action' => 'RECYCLE', 'is_active' => true],
            ['outcome' => 'NOT_INTERESTED', 'cooldown_hours' => 720, 'max_cycles' => 2, 'next_action' => 'EXHAUST', 'is_active' => true],
            ['outcome' => 'WRONG_NUMBER', 'cooldown_hours' => 0, 'max_cycles' => 1, 'next_action' => 'EXHAUST', 'is_active' => true],
            ['outcome' => 'ORDERED', 'cooldown_hours' => 1440, 'max_cycles' => 999, 'next_action' => 'RECYCLE', 'is_active' => true],
        ];

        foreach ($rules as $rule) {
            RecyclingRule::updateOrCreate(
                ['outcome' => $rule['outcome']],
                $rule
            );
        }
    }
}
