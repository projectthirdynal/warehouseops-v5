<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReorderPointAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $alertData
     */
    public function __construct(
        public readonly array $alertData,
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
        $d = $this->alertData;

        return [
            'type' => 'reorder_point_alert',
            'title' => "Reorder Point Alert: {$d['item_name']}",
            'message' => "{$d['item_name']} ({$d['item_sku']}) at {$d['warehouse']} is below reorder point. Available: {$d['available_stock']}, Reorder Point: {$d['reorder_point']}. Suggested reorder qty: {$d['suggested_reorder_qty']}.",
            'url' => '/inventory/reorder-alerts',
            'meta' => [
                'stream' => $d['stream'],
                'item_sku' => $d['item_sku'],
                'warehouse' => $d['warehouse'],
                'available_stock' => $d['available_stock'],
                'reorder_point' => $d['reorder_point'],
                'suggested_reorder_qty' => $d['suggested_reorder_qty'],
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->alertData;

        return (new MailMessage)
            ->subject("Reorder Point Alert: {$d['item_name']} at {$d['warehouse']}")
            ->greeting("Hello {$notifiable->name},")
            ->line('An item has dropped to or below its reorder point and requires restocking.')
            ->line("**Item:** {$d['item_name']} ({$d['item_sku']})")
            ->line('**Type:** '.($d['stream'] === 'product' ? 'Product' : 'Supply'))
            ->line("**Warehouse:** {$d['warehouse']}")
            ->line("**Available Stock:** {$d['available_stock']}")
            ->line("**Reorder Point:** {$d['reorder_point']}")
            ->line("**Suggested Reorder Quantity:** {$d['suggested_reorder_qty']}")
            ->action('View Reorder Alerts', url('/inventory/reorder-alerts'))
            ->line('Please review and place a purchase order if needed.');
    }
}
