<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Shop\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public string $fromStatus,
        public string $toStatus,
        public ?User $changedBy = null,
        public ?string $reason = null,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('shop.inbox'),
        ];

        if ($this->conversation->assigned_agent_id) {
            $channels[] = new PrivateChannel('agent.'.$this->conversation->assigned_agent_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'conversation.status-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'changed_by' => $this->changedBy?->name ?? 'System',
            'reason' => $this->reason,
        ];
    }
}
