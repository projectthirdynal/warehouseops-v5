<?php

declare(strict_types=1);

use App\Domain\Order\Models\Order;
use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;

it('generates order number with correct format', function () {
    $number = Order::generateOrderNumber();

    expect($number)->toStartWith('ORD-' . now()->format('Ymd') . '-');
});

it('generates different number when collision exists', function () {
    $firstNumber = Order::generateOrderNumber();

    DB::table('orders')->insert([
        'order_number' => $firstNumber,
        'status' => OrderStatus::CONFIRMED->value ?? 'confirmed',
        'courier_code' => 'MANUAL',
        'quantity' => 1,
        'unit_price' => 100,
        'total_amount' => 100,
        'receiver_name' => 'Test',
        'receiver_phone' => '09170000000',
        'receiver_address' => 'Test Address',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $secondNumber = Order::generateOrderNumber();

    expect($secondNumber)->not->toBe($firstNumber);
});
