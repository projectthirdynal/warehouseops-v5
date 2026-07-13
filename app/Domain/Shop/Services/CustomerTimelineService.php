<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\Conversation;
use App\Models\Customer;
use Illuminate\Support\Collection;

class CustomerTimelineService
{
    /**
     * Build a chronological activity timeline for a customer.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function build(Customer $customer, ?int $limit = null): Collection
    {
        $activities = collect();

        $orders = $customer->orders()
            ->latest('created_at')
            ->limit($limit ?? 50)
            ->get(['id', 'order_number', 'status', 'total_amount', 'created_at'])
            ->map(fn ($order) => [
                'type' => 'order',
                'occurred_at' => $order->created_at->toIso8601String(),
                'title' => "Order {$order->order_number}",
                'description' => "Status: {$order->status}, Amount: ₱" . number_format((float) $order->total_amount, 2),
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'amount' => $order->total_amount,
                ],
            ]);

        $activities = $activities->merge($orders);

        $notes = $customer->notes()
            ->with('user:id,name')
            ->latest('created_at')
            ->limit($limit ?? 50)
            ->get()
            ->map(fn ($note) => [
                'type' => 'note',
                'occurred_at' => $note->created_at->toIso8601String(),
                'title' => 'Agent note',
                'description' => $note->body,
                'metadata' => [
                    'note_id' => $note->id,
                    'author' => $note->user?->name ?? 'System',
                    'tags' => $note->tags,
                ],
            ]);

        $activities = $activities->merge($notes);

        $messages = $customer->identities()
            ->with(['messages' => fn ($q) => $q->with('conversation:id,channel')->latest('sent_at')->latest('created_at')->limit($limit ?? 50)])
            ->get()
            ->pluck('messages')
            ->flatten()
            ->map(fn ($message) => [
                'type' => 'message',
                'occurred_at' => $message->sent_at?->toIso8601String() ?? $message->created_at->toIso8601String(),
                'title' => ucfirst($message->direction) . ' message',
                'description' => $message->body ?? '(attachment)',
                'metadata' => [
                    'message_id' => $message->id,
                    'direction' => $message->direction,
                    'channel' => $message->conversation?->channel,
                ],
            ]);

        $activities = $activities->merge($messages);

        $conversations = $customer->identities()
            ->with(['conversations' => fn ($q) => $q->latest('created_at')->limit($limit ?? 50)])
            ->get()
            ->pluck('conversations')
            ->flatten()
            ->map(fn (Conversation $conversation) => [
                'type' => 'conversation',
                'occurred_at' => $conversation->created_at->toIso8601String(),
                'title' => 'Conversation started',
                'description' => $conversation->last_message_preview ?? "Channel: {$conversation->channel}",
                'metadata' => [
                    'conversation_id' => $conversation->id,
                    'channel' => $conversation->channel,
                    'status' => $conversation->status,
                ],
            ]);

        $activities = $activities->merge($conversations);

        return $activities
            ->sortByDesc('occurred_at')
            ->values()
            ->when($limit, fn ($collection) => $collection->take($limit));
    }
}
