<?php

declare(strict_types=1);

namespace App\Notifications;

use Modules\Shop\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ConversationStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly string $changedByName,
        public readonly ?string $reason = null,
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
            'type' => 'conversation_status_changed',
            'title' => "Conversation #{$this->conversation->id} status changed",
            'message' => "{$customerName}: {$this->fromStatus} → {$this->toStatus} by {$this->changedByName}",
            'url' => "/shop/inbox/{$this->conversation->id}",
            'meta' => [
                'conversation_id' => $this->conversation->id,
                'from_status' => $this->fromStatus,
                'to_status' => $this->toStatus,
                'changed_by' => $this->changedByName,
                'reason' => $this->reason,
            ],
        ];
    }
}
