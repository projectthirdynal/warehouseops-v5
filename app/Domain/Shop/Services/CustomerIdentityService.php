<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\CustomerIdentity;
use App\Domain\Shop\Models\FacebookPage;
use App\Models\Customer;

class CustomerIdentityService
{
    public function __construct(private readonly PhoneDetectionService $phones) {}

    public function findByPhone(string $phone): ?Customer
    {
        $normalized = $this->phones->normalize($phone);

        return Customer::query()
            ->where('normalized_phone', $normalized)
            ->orWhere('phone', $phone)
            ->first();
    }

    public function firstOrCreateFromPhone(array $attributes): Customer
    {
        $normalized = $this->phones->normalize($attributes['phone']);

        $customer = $this->findByPhone($attributes['phone']);

        if (! $customer) {
            $customer = new Customer([
                'phone' => $attributes['phone'],
                'normalized_phone' => $normalized,
                'name' => $attributes['name'],
                'risk_level' => 'LOW',
            ]);
        }

        $customer->fill([
            'name' => $attributes['name'],
            'normalized_phone' => $normalized,
            'canonical_address' => $attributes['address'] ?? null,
            'landmark' => $attributes['landmark'] ?? null,
            'barangay' => $attributes['barangay'] ?? null,
            'city_municipality' => $attributes['city_municipality'] ?? null,
            'province' => $attributes['province'] ?? null,
            'region' => $attributes['region'] ?? null,
            'last_order_date' => now(),
        ])->save();

        return $customer;
    }

    public function upsertFacebookIdentity(
        FacebookPage $page,
        string $psid,
        ?Customer $customer = null,
        ?string $displayName = null,
        ?string $detectedPhone = null,
        array $metadata = []
    ): CustomerIdentity {
        return CustomerIdentity::query()->updateOrCreate(
            [
                'provider' => 'facebook',
                'provider_user_id' => $psid,
                'facebook_page_id' => $page->id,
            ],
            [
                'customer_id' => $customer?->id,
                'display_name' => $displayName,
                'phone_detected' => $detectedPhone ? $this->phones->normalize($detectedPhone) : null,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'metadata' => $metadata,
            ]
        );
    }
}
