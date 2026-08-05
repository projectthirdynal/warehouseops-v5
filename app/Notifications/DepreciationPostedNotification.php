<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DepreciationPostedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly array $summary,
        public readonly bool $sendEmail = true,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($this->sendEmail && isset($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $s = $this->summary;

        return [
            'type' => 'depreciation_posted',
            'posted_count' => $s['posted_count'],
            'total_amount' => $s['total_amount'],
            'posted_at' => $s['posted_at'],
            'message' => "{$s['posted_count']} depreciation entries posted totaling ".number_format($s['total_amount'], 2),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->summary;

        $mail = (new MailMessage)
            ->subject('Depreciation Posted: '.$s['posted_count'].' entries')
            ->greeting("Hello {$notifiable->name},")
            ->line('Monthly depreciation journal entries have been posted automatically.')
            ->line("**Entries Posted:** {$s['posted_count']}")
            ->line('**Total Depreciation:** '.number_format($s['total_amount'], 2))
            ->line('');

        foreach (array_slice($s['top_entries'], 0, 5) as $entry) {
            $mail->line("• **{$entry['asset_name']}** ({$entry['asset_code']}) — ".number_format($entry['amount'], 2)." — {$entry['reference']}");
        }

        $mail->action('View Depreciation', url('/inventory/depreciation-automation'))
            ->line('Please review the posted entries.');

        return $mail;
    }
}
