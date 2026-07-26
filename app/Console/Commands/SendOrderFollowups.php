<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\Message;
use App\Domain\Shop\Models\OrderRemark;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendOrderFollowups extends Command
{
    protected $signature = 'shop:send-order-followups {--days=3 : Days in DISPATCHED before triggering follow-up}';
    protected $description = 'Post follow-up reminders to conversations for orders stuck in DISPATCHED status.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $orders = Order::query()
            ->where('status', OrderStatus::DISPATCHED)
            ->whereNotNull('conversation_id')
            ->where('dispatched_at', '<', $cutoff)
            ->whereNotIn('id', function ($q) {
                $q->select('order_id')
                    ->from('order_remarks')
                    ->where('type', 'follow_up')
                    ->where('created_at', '>=', now()->subDay());
            })
            ->limit(100)
            ->get();

        $sent = 0;

        foreach ($orders as $order) {
            $conversation = Conversation::find($order->conversation_id);
            if (! $conversation) {
                continue;
            }

            $elapsedDays = $order->dispatched_at
                ? (int) now()->diffInDays($order->dispatched_at)
                : $days;

            $body = "⏰ Follow-up: Order {$order->order_number} has been dispatched for {$elapsedDays} day(s) with no delivery confirmation. Please check courier tracking.";

            Message::query()->create([
                'conversation_id' => $order->conversation_id,
                'facebook_page_id' => $conversation->facebook_page_id,
                'sent_by' => null,
                'external_message_id' => 'system-' . str()->uuid(),
                'direction' => 'system',
                'message_type' => 'order_followup',
                'body' => $body,
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => $order->status->value,
                    'days_dispatched' => $elapsedDays,
                ],
                'sent_at' => now(),
                'send_status' => 'logged',
                'retry_count' => 0,
            ]);

            $conversation->forceFill([
                'last_message_preview' => $body,
                'last_message_at' => now(),
            ])->save();

            OrderRemark::query()->create([
                'order_id' => $order->id,
                'conversation_id' => $order->conversation_id,
                'user_id' => null,
                'type' => 'follow_up',
                'body' => $body,
                'metadata' => ['days_dispatched' => $elapsedDays],
            ]);

            $sent++;
            $this->info("Follow-up posted for order {$order->order_number} ({$elapsedDays}d dispatched) → conversation #{$conversation->id}");
        }

        if ($sent > 0) {
            Log::info("Order follow-ups: {$sent} reminder(s) sent.");
        }

        $this->info("Total follow-ups sent: {$sent}");

        return Command::SUCCESS;
    }
}
