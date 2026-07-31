<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly string $changedByName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $from = ucfirst(str_replace('_', ' ', $this->fromStatus));
        $to = ucfirst(str_replace('_', ' ', $this->toStatus));

        return (new MailMessage())
            ->subject("Ticket {$this->ticket->ticket_number} status changed to {$to}")
            ->greeting("Ticket Status Updated")
            ->line("{$this->changedByName} changed the status of ticket {$this->ticket->ticket_number}.")
            ->line("**Ticket:** {$this->ticket->subject}")
            ->line("**Status:** {$from} → {$to}")
            ->action('View Ticket', url("/tickets/{$this->ticket->id}"));
    }

    public function toDatabase(object $notifiable): array
    {
        $from = ucfirst(str_replace('_', ' ', $this->fromStatus));
        $to = ucfirst(str_replace('_', ' ', $this->toStatus));

        return [
            'type' => 'ticket_status_changed',
            'title' => "Ticket {$this->ticket->ticket_number} status changed",
            'message' => "{$from} → {$to} by {$this->changedByName}",
            'url' => "/tickets/{$this->ticket->id}",
            'meta' => [
                'ticket_id' => $this->ticket->id,
                'ticket_number' => $this->ticket->ticket_number,
                'from_status' => $this->fromStatus,
                'to_status' => $this->toStatus,
                'changed_by' => $this->changedByName,
            ],
        ];
    }
}
