<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketRepliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $repliedByName,
        public readonly string $commentBody,
        public readonly bool $isInternal,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $prefix = $this->isInternal ? '[Internal Note] ' : '';

        return (new MailMessage)
            ->subject("Re: Ticket {$this->ticket->ticket_number} — {$this->ticket->subject}")
            ->greeting('New Reply on Ticket')
            ->line("{$this->repliedByName} has replied to ticket {$this->ticket->ticket_number}.")
            ->line("**Ticket:** {$this->ticket->subject}")
            ->line('**Reply:**')
            ->line($prefix.$this->commentBody)
            ->action('View Ticket', url("/tickets/{$this->ticket->id}"));
    }

    public function toDatabase(object $notifiable): array
    {
        $type = $this->isInternal ? 'ticket_internal_note' : 'ticket_replied';

        return [
            'type' => $type,
            'title' => "Reply on ticket {$this->ticket->ticket_number}",
            'message' => "{$this->repliedByName}: {$this->commentBody}",
            'url' => "/tickets/{$this->ticket->id}",
            'meta' => [
                'ticket_id' => $this->ticket->id,
                'ticket_number' => $this->ticket->ticket_number,
                'replied_by' => $this->repliedByName,
                'is_internal' => $this->isInternal,
            ],
        ];
    }
}
