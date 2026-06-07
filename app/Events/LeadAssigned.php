<?php

namespace App\Events;

use App\Domain\Lead\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Lead $lead,
        public User $agent,
        public LeadCycle $cycle,
        public ?string $reason = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('agent.' . $this->agent->id),
            new PrivateChannel('supervisor.leads'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lead.assigned';
    }

    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->lead->id,
            'customer_name' => $this->lead->name,
            'product' => $this->lead->product_name,
            'province' => $this->lead->state,
            'city' => $this->lead->city,
            'priority' => $this->lead->quality_score ?? 50,
            'reason' => $this->reason,
        ];
    }
}
