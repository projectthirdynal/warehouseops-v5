<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Shop\Models\CourierExportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CourierExportBatchReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly CourierExportBatch $batch,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'courier_export_batch_ready',
            'title' => 'Export Batch Ready',
            'message' => "Courier export batch {$this->batch->batch_number} is ready for download.",
            'url' => '/shop/encoder',
            'meta' => [
                'batch_id' => $this->batch->id,
                'batch_number' => $this->batch->batch_number,
                'courier_code' => $this->batch->courier_code,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $downloadUrl = url("/shop/exports/{$this->batch->id}/download");
        $encoderUrl = url('/shop/encoder');

        return (new MailMessage)
            ->subject("Export Batch {$this->batch->batch_number} is Ready")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your courier export batch **{$this->batch->batch_number}** is ready.")
            ->line("**Courier:** {$this->batch->courier_code}")
            ->line("**Rows:** {$this->batch->row_count}")
            ->when($this->batch->region, fn (MailMessage $mail, string $region) => $mail->line("**Region:** {$region}"))
            ->action('Download CSV', $downloadUrl)
            ->line('You can also view all batches from the Shop Encoder page.')
            ->action('Open Encoder', $encoderUrl);
    }
}
