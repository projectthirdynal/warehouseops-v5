<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\ConversationExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ConversationExportService
{
    public function createExport(array $filters, ?int $userId): ConversationExport
    {
        return DB::transaction(function () use ($filters, $userId) {
            $export = ConversationExport::query()->create([
                'export_number' => $this->exportNumber(),
                'created_by' => $userId,
                'status' => 'processing',
                'filters' => $filters,
            ]);

            $query = Conversation::query()
                ->with([
                    'facebookPage:id,page_name,page_id',
                    'customer:id,name,phone',
                    'identity:id,display_name',
                    'assignedAgent:id,name',
                    'messages' => fn ($q) => $q->orderBy('sent_at'),
                ]);

            if (! empty($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }
            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['sentiment'])) {
                $query->where('sentiment', $filters['sentiment']);
            }

            $conversations = $query->latest('created_at')->get();
            $messageCount = $conversations->sum(fn ($c) => $c->messages->count());

            $csv = $this->generateCsv($conversations);

            $path = "exports/shop/conversations/{$export->export_number}.csv";
            Storage::put($path, $csv);

            $export->forceFill([
                'status' => 'completed',
                'conversation_count' => $conversations->count(),
                'message_count' => $messageCount,
                'file_path' => $path,
                'exported_at' => now(),
            ])->save();

            return $export;
        });
    }

    private function generateCsv($conversations): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'Conversation ID',
            'Thread Key',
            'Channel',
            'Status',
            'Priority',
            'Sentiment',
            'Sentiment Score',
            'Customer Name',
            'Customer Phone',
            'Identity Display Name',
            'Facebook Page',
            'Assigned Agent',
            'Created At',
            'Last Message At',
            'First Response At',
            'Resolved At',
            'First Response Time (s)',
            'Resolution Time (s)',
            'Message Count',
            'Message Body',
            'Message Direction',
            'Message Sent At',
            'Message Type',
        ]);

        foreach ($conversations as $conversation) {
            if ($conversation->messages->isEmpty()) {
                fputcsv($handle, [
                    $conversation->id,
                    $conversation->thread_key,
                    $conversation->channel,
                    $conversation->status,
                    $conversation->priority,
                    $conversation->sentiment,
                    $conversation->sentiment_score,
                    $conversation->customer?->name,
                    $conversation->customer?->phone,
                    $conversation->identity?->display_name,
                    $conversation->facebookPage?->page_name,
                    $conversation->assignedAgent?->name,
                    $conversation->created_at?->format('Y-m-d H:i:s'),
                    $conversation->last_message_at?->format('Y-m-d H:i:s'),
                    $conversation->first_response_at?->format('Y-m-d H:i:s'),
                    $conversation->resolved_at?->format('Y-m-d H:i:s'),
                    $conversation->first_response_time_seconds,
                    $conversation->resolution_time_seconds,
                    0,
                    '',
                    '',
                    '',
                    '',
                ]);
                continue;
            }

            foreach ($conversation->messages as $message) {
                fputcsv($handle, [
                    $conversation->id,
                    $conversation->thread_key,
                    $conversation->channel,
                    $conversation->status,
                    $conversation->priority,
                    $conversation->sentiment,
                    $conversation->sentiment_score,
                    $conversation->customer?->name,
                    $conversation->customer?->phone,
                    $conversation->identity?->display_name,
                    $conversation->facebookPage?->page_name,
                    $conversation->assignedAgent?->name,
                    $conversation->created_at?->format('Y-m-d H:i:s'),
                    $conversation->last_message_at?->format('Y-m-d H:i:s'),
                    $conversation->first_response_at?->format('Y-m-d H:i:s'),
                    $conversation->resolved_at?->format('Y-m-d H:i:s'),
                    $conversation->first_response_time_seconds,
                    $conversation->resolution_time_seconds,
                    $conversation->messages->count(),
                    $message->body ?? '',
                    $message->direction ?? '',
                    $message->sent_at?->format('Y-m-d H:i:s'),
                    $message->message_type ?? '',
                ]);
            }
        }

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    private function exportNumber(): string
    {
        return sprintf('CONV-EXP-%s-%04d', now()->format('Ymd'), ConversationExport::whereDate('created_at', today())->count() + 1);
    }
}
