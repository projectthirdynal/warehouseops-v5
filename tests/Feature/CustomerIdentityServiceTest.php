<?php

declare(strict_types=1);

use App\Domain\Shop\Services\CustomerIdentityService;
use App\Domain\Shop\Services\PhoneDetectionService;
use App\Models\Customer;

it('finds customer by normalized phone', function () {
    $service = app(CustomerIdentityService::class);

    $customer = Customer::factory()->create([
        'phone' => '09171234567',
        'normalized_phone' => '09171234567',
    ]);

    $found = $service->findByPhone('09171234567');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($customer->id);
});

it('finds customer by raw phone when normalized does not match', function () {
    $service = app(CustomerIdentityService::class);

    $customer = Customer::factory()->create([
        'phone' => '+639171234567',
        'normalized_phone' => '09171234567',
    ]);

    $found = $service->findByPhone('+639171234567');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($customer->id);
});

it('does not match unrelated phone numbers with OR clause', function () {
    $service = app(CustomerIdentityService::class);

    Customer::factory()->create([
        'phone' => '09171234567',
        'normalized_phone' => '09171234567',
    ]);

    Customer::factory()->create([
        'phone' => '09189999999',
        'normalized_phone' => '09189999999',
    ]);

    $found = $service->findByPhone('09171234567');

    expect($found)->not->toBeNull();
    expect($found->phone)->toBe('09171234567');
});

it('does not overwrite existing customer name in firstOrCreateFromPhone', function () {
    $service = app(CustomerIdentityService::class);

    $existing = Customer::factory()->create([
        'name' => 'Original Name',
        'phone' => '09171234567',
        'normalized_phone' => '09171234567',
        'canonical_address' => 'Original Address',
    ]);

    $customer = $service->firstOrCreateFromPhone([
        'name' => 'New Name',
        'phone' => '09171234567',
        'canonical_address' => 'New Address',
    ]);

    expect($customer->id)->toBe($existing->id);
    expect($customer->name)->toBe('Original Name');
    expect($customer->canonical_address)->toBe('Original Address');
});

it('creates new customer when phone does not exist', function () {
    $service = app(CustomerIdentityService::class);

    $customer = $service->firstOrCreateFromPhone([
        'name' => 'New Customer',
        'phone' => '09195555555',
        'canonical_address' => '123 Test St',
    ]);

    expect($customer->id)->not->toBeNull();
    expect($customer->name)->toBe('New Customer');
    expect($customer->canonical_address)->toBe('123 Test St');
});
