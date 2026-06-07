<?php

namespace Database\Seeders;

use App\Models\DistributionRule;
use Illuminate\Database\Seeder;

class DistributionRuleSeeder extends Seeder
{
    public function run(): void
    {
        DistributionRule::firstOrCreate(
            ['name' => 'Default Hybrid Distribution'],
            [
                'strategy' => 'hybrid',
                'priority' => 0,
                'filters' => [],
                'weight_formula' => [
                    'w_perf' => 0.30,
                    'w_avail' => 0.25,
                    'w_skill' => 0.20,
                    'w_reg' => 0.15,
                    'w_load' => 0.05,
                    'w_time' => 0.05,
                ],
                'is_active' => true,
                'supervisor_id' => null,
            ]
        );

        DistributionRule::firstOrCreate(
            ['name' => 'High-Priority Skill Match'],
            [
                'strategy' => 'skill_match',
                'priority' => 1,
                'filters' => ['product_skills' => []],
                'weight_formula' => [
                    'w_perf' => 0.20,
                    'w_avail' => 0.20,
                    'w_skill' => 0.50,
                    'w_reg' => 0.05,
                    'w_load' => 0.03,
                    'w_time' => 0.02,
                ],
                'is_active' => false,
                'supervisor_id' => null,
            ]
        );

        DistributionRule::firstOrCreate(
            ['name' => 'Territory-Based Routing'],
            [
                'strategy' => 'territory',
                'priority' => 2,
                'filters' => ['regions' => []],
                'weight_formula' => [
                    'w_perf' => 0.20,
                    'w_avail' => 0.20,
                    'w_skill' => 0.05,
                    'w_reg' => 0.50,
                    'w_load' => 0.03,
                    'w_time' => 0.02,
                ],
                'is_active' => false,
                'supervisor_id' => null,
            ]
        );
    }
}
