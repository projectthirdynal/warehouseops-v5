<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Inventory\Models\StockAdjustment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StockAdjustmentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly StockAdjustment $adjustment,
        public readonly string $event,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        $item = $this->adjustment->product?->name ?? $this->adjustment->supply?->name ?? 'Unknown item';

        return match ($this->event) {
            'submitted' => [
                'type' => 'adjustment_submitted',
                'title' => 'Stock Adjustment Pending Approval',
                'message' => "A stock adjustment for {$item} requires your approval (variance: {$this->adjustment->variance}).",
                'url' => '/inventory/adjustments',
                'meta' => ['adjustment_id' => $this->adjustment->id],
            ],
            'approved' => [
                'type' => 'adjustment_decided',
                'title' => 'Stock Adjustment Approved',
                'message' => "Your stock adjustment for {$item} has been approved.",
                'url' => '/inventory/adjustments',
                'meta' => ['adjustment_id' => $this->adjustment->id],
            ],
            default => [
                'type' => 'adjustment_decided',
                'title' => 'Stock Adjustment Rejected',
                'message' => "Your stock adjustment for {$item} was rejected.",
                'url' => '/inventory/adjustments',
                'meta' => ['adjustment_id' => $this->adjustment->id],
            ],
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $item = $this->adjustment->product?->name ?? $this->adjustment->supply?->name ?? 'Unknown item';

        return (new MailMessage)
            ->subject(match ($this->event) {
                'submitted' => 'Stock Adjustment Requires Approval',
                'approved' => 'Stock Adjustment Approved',
                default => 'Stock Adjustment Rejected',
            })
            ->greeting("Hello {$notifiable->name},")
            ->line(match ($this->event) {
                'submitted' => "A stock adjustment for **{$item}** has been submitted and requires your approval.",
                'approved' => "The stock adjustment for **{$item}** has been approved and stock levels have been updated.",
                default => "The stock adjustment for **{$item}** has been rejected.",
            })
            ->line("**Warehouse:** {$this->adjustment->warehouse?->name}")
            ->line("**Variance:** {$this->adjustment->variance}")
            ->action('View Adjustments', url('/inventory/adjustments'));
    }
}
