<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeadStockAlertNotification extends Notification implements ShouldQueue
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
            'type' => 'dead_stock_alert',
            'total_dead' => $s['total_dead'],
            'total_dead_value' => $s['total_dead_value'],
            'scanned_at' => $s['scanned_at'],
            'top_items' => array_map(fn ($item) => [
                'sku' => $item['sku'],
                'name' => $item['name'],
                'stream' => $item['stream'],
                'days_idle' => $item['days_idle'],
                'total_value' => $item['total_value'],
                'warehouse' => $item['warehouse'],
            ], $s['top_items']),
            'message' => "{$s['total_dead']} dead stock items found worth ".number_format($s['total_dead_value'], 2),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->summary;

        $mail = (new MailMessage)
            ->subject('Dead Stock Alert: '.$s['total_dead'].' items found')
            ->greeting("Hello {$notifiable->name},")
            ->line('A dead stock scan has identified items that have not moved for an extended period.')
            ->line("**Total Dead Items:** {$s['total_dead']}")
            ->line('**Total Dead Value:** '.number_format($s['total_dead_value'], 2))
            ->line('**Top dead stock items:**')
            ->line('');

        foreach (array_slice($s['top_items'], 0, 5) as $item) {
            $type = $item['stream'] === 'product' ? 'Product' : 'Supply';
            $mail->line("• **{$item['name']}** ({$item['sku']}) — {$type}, {$item['days_idle']} days idle, ".number_format($item['total_value'], 2)." at {$item['warehouse']}");
        }

        $mail->action('View Dead Stock', url('/inventory/dead-stock-automation'))
            ->line('Please review and consider write-offs or markdowns.');

        return $mail;
    }
}
