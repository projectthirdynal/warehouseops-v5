<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use App\Models\Customer;
use Modules\Shop\Models\CustomerIdentity;
use Modules\Shop\Models\FacebookPage;

class CustomerIdentityService
{
    public function __construct(private readonly PhoneDetectionService $phones) {}

    public function findByPhone(string $phone): ?Customer
    {
        $normalized = $this->phones->normalize($phone);

        return Customer::query()
            ->where(function ($query) use ($normalized, $phone) {
                $query
                    ->where('normalized_phone', $normalized)
                    ->orWhere('phone', $phone);
            })
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

        $updateData = [
            'normalized_phone' => $normalized,
            'last_order_date' => now(),
        ];

        if (! empty($attributes['name']) && empty($customer->name)) {
            $updateData['name'] = $attributes['name'];
        }

        $addressFields = ['canonical_address', 'landmark', 'barangay', 'city_municipality', 'province', 'region'];
        foreach ($addressFields as $field) {
            if (isset($attributes[$field]) && empty($customer->$field)) {
                $updateData[$field] = $attributes[$field];
            }
        }

        $customer->fill($updateData)->save();

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
