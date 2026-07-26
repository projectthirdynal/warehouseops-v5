<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Shop\Models\OrderRemark;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RemarkMentionedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly OrderRemark $remark,
        public readonly string $mentionerName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $order = $this->remark->order;
        $orderNumber = $order?->order_number ?? (string) $this->remark->order_id;
        $snippet = $this->truncate($this->remark->body, 80);

        return [
            'type' => 'remark_mentioned',
            'title' => 'Mentioned in order remark',
            'message' => "{$this->mentionerName} mentioned you on order #{$orderNumber}: \"{$snippet}\"",
            'url' => "/shop/orders/{$this->remark->order_id}",
            'meta' => [
                'remark_id' => $this->remark->id,
                'order_id' => $this->remark->order_id,
                'order_number' => $order?->order_number,
                'mentioner' => $this->mentionerName,
            ],
        ];
    }

    private function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit) . '...'
            : $text;
    }
}
