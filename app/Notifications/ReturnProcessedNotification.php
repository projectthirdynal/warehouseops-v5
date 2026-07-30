<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Waybill\Models\ReturnReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReturnProcessedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ReturnReceipt $receipt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        $waybill = $this->receipt->waybill;
        $codAmount = $waybill?->cod_amount ?? $waybill?->amount ?? 0;

        return [
            'type'    => 'return_processed',
            'title'   => 'Returned Parcel Received',
            'message' => "Waybill {$waybill?->waybill_number} received ({$this->receipt->condition}). COD at risk: ₱" . number_format((float) $codAmount, 2),
            'url'     => "/waybills/{$waybill?->id}",
            'meta'    => [
                'receipt_id'      => $this->receipt->id,
                'waybill_id'      => $waybill?->id,
                'waybill_number'  => $waybill?->waybill_number,
                'condition'       => $this->receipt->condition,
                'cod_amount'      => (float) $codAmount,
                'inventory_updated' => $this->receipt->inventory_updated,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $waybill = $this->receipt->waybill;
        $codAmount = $waybill?->cod_amount ?? $waybill?->amount ?? 0;

        return (new MailMessage)
            ->subject("Return Received — {$waybill?->waybill_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A returned parcel has been received at the warehouse.")
            ->line("**Waybill:** {$waybill?->waybill_number}")
            ->line("**Courier:** {$waybill?->courier_provider}")
            ->line("**Receiver:** {$waybill?->receiver_name}")
            ->line("**Condition:** {$this->receipt->condition}")
            ->line("**COD Amount:** ₱" . number_format((float) $codAmount, 2))
            ->line("**Inventory Updated:** " . ($this->receipt->inventory_updated ? 'Yes' : 'No'))
            ->action('View Waybill', url("/waybills/{$waybill?->id}"));
    }
}
