<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?string $fromAssigneeName,
        public readonly string $toAssigneeName,
        public readonly string $assignedByName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $from = $this->fromAssigneeName ?? 'Unassigned';

        return (new MailMessage())
            ->subject("Ticket {$this->ticket->ticket_number} assigned to {$this->toAssigneeName}")
            ->greeting("Ticket Assignment")
            ->line("{$this->assignedByName} has assigned ticket {$this->ticket->ticket_number} to {$this->toAssigneeName}.")
            ->line("**Ticket:** {$this->ticket->subject}")
            ->line("**Previous assignee:** {$from}")
            ->line("**New assignee:** {$this->toAssigneeName}")
            ->action('View Ticket', url("/tickets/{$this->ticket->id}"));
    }

    public function toDatabase(object $notifiable): array
    {
        $from = $this->fromAssigneeName ?? 'Unassigned';

        return [
            'type' => 'ticket_assigned',
            'title' => "Ticket {$this->ticket->ticket_number} assigned",
            'message' => "{$from} → {$this->toAssigneeName} by {$this->assignedByName}",
            'url' => "/tickets/{$this->ticket->id}",
            'meta' => [
                'ticket_id' => $this->ticket->id,
                'ticket_number' => $this->ticket->ticket_number,
                'from_assignee' => $this->fromAssigneeName,
                'to_assignee' => $this->toAssigneeName,
                'assigned_by' => $this->assignedByName,
            ],
        ];
    }
}
