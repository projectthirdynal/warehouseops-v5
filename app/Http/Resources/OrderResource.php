<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'lead_id' => $this->lead_id,
            'customer_id' => $this->customer_id,
            'product_id' => $this->product_id,
            'variant_id' => $this->variant_id,
            'assigned_agent_id' => $this->assigned_agent_id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'courier_code' => $this->courier_code,
            'quantity' => $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total_amount' => (float) $this->total_amount,
            'cod_amount' => (float) $this->cod_amount,
            'shipping_cost' => (float) $this->shipping_cost,
            'discount_amount' => (float) $this->discount_amount,
            'receiver_name' => $this->receiver_name,
            'receiver_phone' => $this->receiver_phone,
            'receiver_address' => $this->receiver_address,
            'city' => $this->city,
            'state' => $this->state,
            'barangay' => $this->barangay,
            'postal_code' => $this->postal_code,
            'landmark' => $this->landmark,
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'address_details' => [
                'province' => $this->state,
                'city' => $this->city,
                'barangay' => $this->barangay,
                'address' => $this->receiver_address,
                'landmark' => $this->landmark,
                'postal_code' => $this->postal_code,
            ],
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            'lead' => $this->whenLoaded('lead', fn () => [
                'id' => $this->lead->id,
                'name' => $this->lead->name,
                'phone' => $this->lead->phone,
                'product_name' => $this->lead->product_name,
                'product_brand' => $this->lead->product_brand,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'total_orders' => $this->customer->total_orders ?? 0,
                'successful_orders' => $this->customer->successful_orders ?? 0,
                'risk_level' => $this->customer->risk_level,
                'is_blacklisted' => $this->customer->is_blacklisted,
            ]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'brand' => $this->product->brand,
            ]),
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'name' => $this->variant->name,
                'sku' => $this->variant->sku,
            ]),
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
            ]),
            'shop_items' => $this->whenLoaded('shopItems', fn () => $this->shopItems->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'metadata' => $item->metadata,
            ])),
        ];
    }
}
