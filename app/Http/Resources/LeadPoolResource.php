<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadPoolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone, // Supervisors CAN see phone
            'city' => $this->city,
            'state' => $this->state,
            'barangay' => $this->barangay,
            'source' => $this->source,
            'product_name' => $this->product_name,
            'product_brand' => $this->product_brand,
            'amount' => $this->amount,
            'status' => $this->status,
            'sales_status' => $this->sales_status,
            'pool_status' => $this->pool_status,
            'total_cycles' => $this->total_cycles,
            'is_exhausted' => $this->is_exhausted,
            'cooldown_until' => $this->cooldown_until?->toISOString(),
            'assigned_to' => $this->assigned_to,
            'assigned_agent' => $this->whenLoaded('assignedAgent', fn() => $this->assignedAgent ? [
                'id' => $this->assignedAgent->id,
                'name' => $this->assignedAgent->name,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn() => $this->customer ? [
                'id' => $this->customer->id,
                'total_orders' => $this->customer->total_orders ?? 0,
                'success_rate' => $this->customer->success_rate ?? 0,
                'is_blacklisted' => $this->customer->is_blacklisted ?? false,
            ] : null),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
