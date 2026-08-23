<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Promo\Enums\PromoType;
use App\Domain\Promo\Models\Promo;
use Illuminate\Database\Seeder;

class PromoSeeder extends Seeder
{
    public function run(): void
    {
        $promos = [
            [
                'promo_code' => 'B1T1-GEN',
                'name' => 'Buy 1 Take 1 (Generic)',
                'description' => 'Buy 1, get 1 free of the same product',
                'type' => PromoType::BUNDLE,
                'trigger_quantity' => 1,
                'free_quantity' => 1,
                'is_active' => true,
            ],
            [
                'promo_code' => 'B1T2-GEN',
                'name' => 'Buy 1 Take 2 (Generic)',
                'description' => 'Buy 1, get 2 free of the same product',
                'type' => PromoType::BUNDLE,
                'trigger_quantity' => 1,
                'free_quantity' => 2,
                'is_active' => true,
            ],
            [
                'promo_code' => 'B2T1-GEN',
                'name' => 'Buy 2 Take 1 (Generic)',
                'description' => 'Buy 2, get 1 free of the same product',
                'type' => PromoType::BUNDLE,
                'trigger_quantity' => 2,
                'free_quantity' => 1,
                'is_active' => true,
            ],
            [
                'promo_code' => 'FREEBIE-SAMPLE',
                'name' => 'Free Sample Pack',
                'description' => 'Customer gets a free sample pack with their order',
                'type' => PromoType::FREEBIE,
                'free_quantity' => 1,
                'free_item_name' => 'Sample Pack',
                'is_active' => true,
            ],
            [
                'promo_code' => 'DISC-10PCT',
                'name' => '10% Discount',
                'description' => '10% off the order total',
                'type' => PromoType::DISCOUNT,
                'discount_percentage' => 10,
                'is_active' => true,
            ],
            [
                'promo_code' => 'DISC-15PCT',
                'name' => '15% Discount',
                'description' => '15% off the order total',
                'type' => PromoType::DISCOUNT,
                'discount_percentage' => 15,
                'is_active' => false,
            ],
        ];

        foreach ($promos as $promo) {
            Promo::firstOrCreate(
                ['promo_code' => $promo['promo_code']],
                $promo,
            );
        }
    }
}
