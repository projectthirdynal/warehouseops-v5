<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

class CustomerAddressService
{
    /**
     * Record or update an address in the customer's history.
     *
     * @param  array<string, mixed>  $addressData
     */
    public function record(Customer $customer, array $addressData, bool $setDefault = false, ?string $source = null): CustomerAddress
    {
        $payload = [
            'label' => $addressData['label'] ?? null,
            'canonical_address' => $addressData['canonical_address'] ?? null,
            'landmark' => $addressData['landmark'] ?? null,
            'barangay' => $addressData['barangay'] ?? null,
            'city_municipality' => $addressData['city_municipality'] ?? null,
            'province' => $addressData['province'] ?? null,
            'region' => $addressData['region'] ?? null,
            'postal_code' => $addressData['postal_code'] ?? null,
            'country' => $addressData['country'] ?? 'Philippines',
            'contact_name' => $addressData['contact_name'] ?? null,
            'contact_phone' => $addressData['contact_phone'] ?? null,
            'source' => $source,
            'used_at' => now(),
        ];

        return DB::transaction(function () use ($customer, $payload, $setDefault) {
            $address = $customer->addresses()->firstOrNew([
                'canonical_address' => $payload['canonical_address'],
                'barangay' => $payload['barangay'],
                'city_municipality' => $payload['city_municipality'],
                'province' => $payload['province'],
                'region' => $payload['region'],
            ]);

            $isNew = ! $address->exists;
            $address->forceFill($payload)->save();

            if ($setDefault || ($isNew && $customer->addresses()->count() === 1)) {
                CustomerAddress::setDefault($customer, $address);
            }

            return $address;
        });
    }

    /**
     * Set the default address for a customer.
     */
    public function setDefault(Customer $customer, CustomerAddress $address): CustomerAddress
    {
        CustomerAddress::setDefault($customer, $address);

        return $address->fresh();
    }
}
