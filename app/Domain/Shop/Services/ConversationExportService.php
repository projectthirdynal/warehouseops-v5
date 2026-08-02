<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\ConversationExport;
use Illuminate\Support\Facades\Storage;

class ConversationExportService
{
    private const CHUNK_SIZE = 100;

    public function createExport(array $filters, ?int $userId): ConversationExport
    {
        $export = ConversationExport::query()->create([
            'export_number' => $this->exportNumber(),
            'created_by' => $userId,
            'status' => 'processing',
            'filters' => $filters,
        ]);

        $path = "exports/shop/conversations/{$export->export_number}.csv";

        $tempPath = storage_path("app/temp/{$export->export_number}.csv");
        if (! is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $handle = fopen($tempPath, 'w');
        fputcsv($handle, $this->csvHeaders());

        $conversationCount = 0;
        $messageCount = 0;

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

        $query->latest('created_at')->chunk(self::CHUNK_SIZE, function ($conversations) use ($handle, &$conversationCount, &$messageCount) {
            foreach ($conversations as $conversation) {
                $conversationCount++;
                $msgCount = $conversation->messages->count();
                $messageCount += $msgCount;

                if ($conversation->messages->isEmpty()) {
                    fputcsv($handle, $this->conversationRow($conversation, 0, '', '', '', ''));
                    continue;
                }

                foreach ($conversation->messages as $message) {
                    fputcsv($handle, $this->conversationRow(
                        $conversation,
                        $msgCount,
                        $message->body ?? '',
                        $message->direction ?? '',
                        $message->sent_at?->format('Y-m-d H:i:s') ?? '',
                        $message->message_type ?? '',
                    ));
                }
            }
        });

        fclose($handle);

        Storage::put($path, file_get_contents($tempPath));
        unlink($tempPath);

        $export->forceFill([
            'status' => 'completed',
            'conversation_count' => $conversationCount,
            'message_count' => $messageCount,
            'file_path' => $path,
            'exported_at' => now(),
        ])->save();

        return $export;
    }

    private function csvHeaders(): array
    {
        return [
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
        ];
    }

    private function conversationRow(Conversation $conversation, int $msgCount, string $body, string $direction, string $sentAt, string $msgType): array
    {
        return [
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
            $msgCount,
            $body,
            $direction,
            $sentAt,
            $msgType,
        ];
    }

    private function exportNumber(): string
    {
        return sprintf('CONV-EXP-%s-%04d', now()->format('Ymd'), ConversationExport::whereDate('created_at', today())->count() + 1);
    }
}
