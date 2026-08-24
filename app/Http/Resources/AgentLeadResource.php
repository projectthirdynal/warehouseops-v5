<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentLeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'street' => $this->street,
            'city' => $this->city,
            'state' => $this->state,
            'barangay' => $this->barangay,
            'postal_code' => $this->postal_code,
            'address_details' => [
                'province' => $this->state,
                'city' => $this->city,
                'barangay' => $this->barangay,
                'address' => $this->address ?? $this->street,
                'landmark' => $this->landmark,
                'postal_code' => $this->postal_code,
            ],
            'product_name' => $this->product_name,
            'product_brand' => $this->product_brand,
            'amount' => $this->amount,
            'status' => $this->status,
            'sales_status' => $this->sales_status,
            'pool_status' => $this->pool_status,
            'total_cycles' => $this->total_cycles,
            'call_attempts' => $this->call_attempts,
            'last_called_at' => $this->last_called_at?->toISOString(),
            'assigned_at' => $this->assigned_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),

            // Customer info with full address details
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'canonical_address' => $this->customer->canonical_address,
                'landmark' => $this->customer->landmark,
                'barangay' => $this->customer->barangay,
                'city_municipality' => $this->customer->city_municipality,
                'province' => $this->customer->province,
                'region' => $this->customer->region,
                'preferred_courier' => $this->customer->preferred_courier,
                'payment_method' => $this->customer->payment_method,
                'total_orders' => $this->customer->total_orders ?? 0,
                'successful_orders' => $this->customer->successful_orders ?? 0,
                'success_rate' => $this->customer->success_rate ?? 0,
                'risk_level' => $this->customer->risk_level,
                'is_blacklisted' => $this->customer->is_blacklisted,
            ]),

            // Cycles with call history
            'cycles' => $this->whenLoaded('cycles', fn () => $this->cycles->map(fn ($cycle) => [
                'id' => $cycle->id,
                'cycle_number' => $cycle->cycle_number,
                'status' => $cycle->status,
                'outcome' => $cycle->outcome,
                'call_count' => $cycle->call_count,
                'last_call_at' => $cycle->last_call_at?->toISOString(),
                'callback_at' => $cycle->callback_at?->toISOString(),
                'callback_notes' => $cycle->callback_notes,
                'opened_at' => $cycle->opened_at?->toISOString(),
                'closed_at' => $cycle->closed_at?->toISOString(),
            ])
            ),
        ];
    }
}
