<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $createdByName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $priority = ucfirst($this->ticket->priority);
        $due = $this->ticket->due_at?->format('M d, Y g:i A') ?? 'N/A';

        return (new MailMessage())
            ->subject("New Ticket {$this->ticket->ticket_number}: {$this->ticket->subject}")
            ->greeting("New Support Ticket")
            ->line("A new ticket has been created by {$this->createdByName}.")
            ->line("**Ticket #:** {$this->ticket->ticket_number}")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line("**Priority:** {$priority}")
            ->line("**Due:** {$due}")
            ->line("**Description:**")
            ->line($this->ticket->description ?? 'No description provided.')
            ->action('View Ticket', url("/tickets/{$this->ticket->id}"));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ticket_created',
            'title' => "New ticket {$this->ticket->ticket_number}",
            'message' => "{$this->ticket->subject} — created by {$this->createdByName}",
            'url' => "/tickets/{$this->ticket->id}",
            'meta' => [
                'ticket_id' => $this->ticket->id,
                'ticket_number' => $this->ticket->ticket_number,
                'priority' => $this->ticket->priority,
                'created_by' => $this->createdByName,
            ],
        ];
    }
}
