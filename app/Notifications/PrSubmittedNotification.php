<?php

declare(strict_types=1);

namespace App\Notifications;

use Modules\Procurement\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PrSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PurchaseRequest $pr) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'pr_submitted',
            'title' => 'Purchase Request Pending Approval',
            'message' => "PR #{$this->pr->pr_number} submitted by {$this->pr->requester?->name} requires your approval.",
            'url' => "/procurement/requests/{$this->pr->id}",
            'meta' => [
                'pr_id' => $this->pr->id,
                'pr_number' => $this->pr->pr_number,
                'amount' => $this->pr->estimated_total,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Action Required: PR #{$this->pr->pr_number} Needs Approval")
            ->greeting("Hello {$notifiable->name},")
            ->line('A Purchase Request has been submitted and requires your approval.')
            ->line("**PR Number:** {$this->pr->pr_number}")
            ->line("**Requested by:** {$this->pr->requester?->name}")
            ->line("**Department:** {$this->pr->department}")
            ->line('**Estimated Total:** ₱'.number_format((float) $this->pr->estimated_total, 2))
            ->action('Review & Approve', url("/procurement/requests/{$this->pr->id}"))
            ->line('Please review and take action at your earliest convenience.');
    }
}
