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
            ])
            ->toArray();

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
            ])
            ->toArray();

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
            ])
            ->toArray();

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
            ])
            ->toArray();

        $all = array_merge($orders, $notes, $messages, $conversations);
        usort($all, fn ($a, $b) => strcmp($b['occurred_at'] ?? '', $a['occurred_at'] ?? ''));
        $all = array_slice($all, 0, $limit ?? count($all));

        return collect($all);
    }
}
