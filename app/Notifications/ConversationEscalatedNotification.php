<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Shop\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ConversationEscalatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly string $reason,
        public readonly int $elapsedMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $customerName = $this->conversation->customer?->name
            ?? $this->conversation->identity?->display_name
            ?? 'Unknown Customer';

        return [
            'type' => 'conversation_escalated',
            'title' => "Conversation #{$this->conversation->id} escalated",
            'message' => "{$customerName}: SLA breached ({$this->elapsedMinutes}m in {$this->conversation->status}) — {$this->reason}",
            'url' => "/shop/inbox/{$this->conversation->id}",
            'meta' => [
                'conversation_id' => $this->conversation->id,
                'status' => $this->conversation->status,
                'elapsed_minutes' => $this->elapsedMinutes,
                'reason' => $this->reason,
            ],
        ];
    }
}
