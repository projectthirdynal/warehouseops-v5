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
        $facebookName = $attributes['facebook_name'] ?? null;

        $customer = $this->findByPhone($attributes['phone']);

        if (! $customer) {
            $customer = new Customer([
                'phone' => $attributes['phone'],
                'normalized_phone' => $normalized,
                'name' => $this->preferredCustomerName(null, $attributes['name'] ?? null, $facebookName),
                'risk_level' => 'LOW',
            ]);
        }

        $updates = [
            'name' => $this->preferredCustomerName($customer, $attributes['name'] ?? null, $facebookName),
            'normalized_phone' => $normalized,
            'last_order_date' => now(),
        ];

        if (filled($facebookName)) {
            $updates['facebook_name'] = $facebookName;
        }

        foreach ([
            'address' => 'canonical_address',
            'landmark' => 'landmark',
            'barangay' => 'barangay',
            'city_municipality' => 'city_municipality',
            'province' => 'province',
            'region' => 'region',
        ] as $attribute => $column) {
            if (array_key_exists($attribute, $attributes) && $attributes[$attribute] !== null) {
                $updates[$column] = $attributes[$attribute];
            }
        }

        $customer->fill($updates)->save();

        return $customer;
    }

    public function upsertFacebookIdentity(
        FacebookPage $page,
        string $psid,
        ?Customer $customer = null,
        ?string $displayName = null,
        ?string $profilePicUrl = null,
        ?string $detectedPhone = null,
        array $metadata = []
    ): CustomerIdentity {
        $identity = CustomerIdentity::query()->firstOrNew([
            'provider' => 'facebook',
            'provider_user_id' => $psid,
            'facebook_page_id' => $page->id,
        ]);

        $identity->fill([
            'customer_id' => $customer?->id ?? $identity->customer_id,
            'display_name' => filled($displayName) ? $displayName : $identity->display_name,
            'profile_pic_url' => filled($profilePicUrl) ? $profilePicUrl : $identity->profile_pic_url,
            'phone_detected' => $detectedPhone ? $this->phones->normalize($detectedPhone) : $identity->phone_detected,
            'first_seen_at' => $identity->first_seen_at ?? now(),
            'last_seen_at' => now(),
            'metadata' => array_merge($identity->metadata ?? [], $metadata),
        ])->save();

        return $identity;
    }

    private function preferredCustomerName(?Customer $customer, ?string $incomingName, ?string $facebookName): string
    {
        if ($customer && filled($customer->name) && ! $this->isGenericCustomerName($customer->name)) {
            return $customer->name;
        }

        if (filled($incomingName) && ! $this->isGenericCustomerName($incomingName)) {
            return $incomingName;
        }

        if (filled($facebookName)) {
            return $facebookName;
        }

        return 'Facebook Customer';
    }

    private function isGenericCustomerName(?string $name): bool
    {
        return in_array(mb_strtolower(trim((string) $name)), [
            '',
            'facebook customer',
            'customer',
            'unknown customer',
        ], true);
    }
}
