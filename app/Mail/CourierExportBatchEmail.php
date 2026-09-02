<?php

declare(strict_types=1);

namespace App\Mail;

use Modules\Shop\Models\CourierExportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CourierExportBatchEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly CourierExportBatch $batch,
        public readonly ?string $shareUrl = null,
        public readonly ?string $customMessage = null,
        public readonly ?string $senderName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Courier Export Batch {$this->batch->batch_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.courier-export-batch',
            with: [
                'batch' => $this->batch,
                'shareUrl' => $this->shareUrl,
                'customMessage' => $this->customMessage,
                'senderName' => $this->senderName ?? 'WarehouseOps System',
                'encoderUrl' => url('/shop/encoder'),
            ],
        );
    }
}
