<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Procurement\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PrDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PurchaseRequest $pr,
        public readonly string $decision,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        $approved = $this->decision === 'approved';
        return [
            'type'    => 'pr_decided',
            'title'   => $approved ? 'Purchase Request Approved' : 'Purchase Request Rejected',
            'message' => $approved
                ? "Your PR #{$this->pr->pr_number} has been approved by {$this->pr->approver?->name}."
                : "Your PR #{$this->pr->pr_number} was rejected. Reason: {$this->pr->rejected_reason}",
            'url'     => "/procurement/requests/{$this->pr->id}",
            'meta'    => [
                'pr_id'     => $this->pr->id,
                'pr_number' => $this->pr->pr_number,
                'decision'  => $this->decision,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->decision === 'approved';
        $mail = (new MailMessage)
            ->subject($approved
                ? "PR #{$this->pr->pr_number} Approved"
                : "PR #{$this->pr->pr_number} Rejected")
            ->greeting("Hello {$notifiable->name},");

        if ($approved) {
            $mail->line("Your Purchase Request **{$this->pr->pr_number}** has been **approved**.")
                 ->line("Approved by: {$this->pr->approver?->name}")
                 ->action('View Purchase Request', url("/procurement/requests/{$this->pr->id}"));
        } else {
            $mail->line("Your Purchase Request **{$this->pr->pr_number}** has been **rejected**.")
                 ->line("**Reason:** {$this->pr->rejected_reason}")
                 ->action('View Purchase Request', url("/procurement/requests/{$this->pr->id}"))
                 ->line('You may revise and resubmit if needed.');
        }

        return $mail;
    }
}
