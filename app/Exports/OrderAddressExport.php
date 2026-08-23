<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Rap2hpoutre\FastExcel\FastExcel;

class OrderAddressExport
{
    public function __construct(
        private Builder $query,
    ) {}

    public function download(string $filename)
    {
        $generator = function () {
            foreach ($this->query->cursor() as $order) {
                /** @var Order $order */
                yield [
                    'Order Number' => $order->order_number,
                    'Receiver Name' => $order->receiver_name,
                    'Phone' => $order->receiver_phone,
                    'Province' => $order->state,
                    'City' => $order->city,
                    'Barangay' => $order->barangay,
                    'Address' => $order->receiver_address,
                    'Landmark' => $order->landmark,
                    'Postal Code' => $order->postal_code,
                    'Status' => $order->status?->value,
                    'Created At' => $order->created_at?->setTimezone('Asia/Manila')->format('Y-m-d H:i'),
                ];
            }
        };

        return (new FastExcel($generator()))->download($filename);
    }
}
