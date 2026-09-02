<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Shop\Models\Conversation;
use Modules\Shop\Models\Message;

class ConversationArchiveService
{
    public const DEFAULT_ARCHIVE_DAYS = 90;

    public const DEFAULT_COMPRESS_DAYS = 180;

    public const BATCH_SIZE = 50;

    public function archive(Conversation $conversation): array
    {
        if ($conversation->archived_at) {
            return ['success' => false, 'message' => 'Conversation already archived.'];
        }

        $messageCount = $conversation->messages()->count();
        $conversation->forceFill([
            'status' => Conversation::STATUS_ARCHIVED,
            'archived_at' => now(),
            'message_count' => $messageCount,
        ])->save();

        return [
            'success' => true,
            'conversation_id' => $conversation->id,
            'message_count' => $messageCount,
            'message' => "Conversation #{$conversation->id} archived ({$messageCount} messages).",
        ];
    }

    public function bulkArchive(int $limit = self::BATCH_SIZE): array
    {
        $days = (int) $this->getSettings()['archive_after_days'];
        $conversations = Conversation::archivable($days)->limit($limit)->get();

        $archived = 0;
        $skipped = 0;
        $totalMessages = 0;

        foreach ($conversations as $conversation) {
            $result = $this->archive($conversation);
            if ($result['success']) {
                $archived++;
                $totalMessages += $result['message_count'];
            } else {
                $skipped++;
            }
        }

        return [
            'archived' => $archived,
            'skipped' => $skipped,
            'total_messages' => $totalMessages,
            'total' => $conversations->count(),
            'message' => "{$archived} conversation(s) archived, {$skipped} skipped.",
        ];
    }

    public function compress(Conversation $conversation): array
    {
        if (! $conversation->archived_at) {
            return ['success' => false, 'message' => 'Conversation must be archived before compression.'];
        }

        if ($conversation->compressed_at) {
            return ['success' => false, 'message' => 'Conversation already compressed.'];
        }

        $messages = $conversation->messages()->orderBy('sent_at')->get();

        if ($messages->isEmpty()) {
            $conversation->forceFill(['compressed_at' => now()])->save();

            return [
                'success' => true,
                'conversation_id' => $conversation->id,
                'messages_compressed' => 0,
                'bytes_saved' => 0,
                'message' => "Conversation #{$conversation->id} compressed (0 messages).",
            ];
        }

        $summary = $this->buildMessageSummary($messages);
        $filename = "archived-conversations/conversation_{$conversation->id}.json";
        $payload = json_encode([
            'conversation_id' => $conversation->id,
            'archived_at' => $conversation->archived_at?->toIso8601String(),
            'compressed_at' => now()->toIso8601String(),
            'message_count' => $messages->count(),
            'summary' => $summary,
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'message_type' => $m->message_type,
                'body' => $m->body,
                'sent_by' => $m->sent_by,
                'sent_at' => $m->sent_at?->toIso8601String(),
                'attachments' => $m->attachments,
                'metadata' => $m->metadata,
            ])->toArray(),
        ], JSON_PRETTY_PRINT);

        $disk = Storage::disk($this->archiveDisk());
        $dbBytes = $messages->sum(function ($m) {
            return strlen((string) $m->body) + strlen((string) json_encode($m->attachments ?? [])) + strlen((string) json_encode($m->metadata ?? []));
        });

        return DB::transaction(function () use ($conversation, $messages, $filename, $payload, $disk, $dbBytes) {
            $disk->put($filename, $payload);
            $fileSize = $disk->size($filename);

            $conversation->forceFill(['compressed_at' => now()])->save();

            $conversation->messages()->delete();

            return [
                'success' => true,
                'conversation_id' => $conversation->id,
                'messages_compressed' => $messages->count(),
                'bytes_saved' => max(0, $dbBytes - $fileSize),
                'archive_file' => $filename,
                'message' => "Conversation #{$conversation->id} compressed ({$messages->count()} messages, ".number_format(max(0, $dbBytes - $fileSize)).' bytes saved).',
            ];
        });
    }

    public function bulkCompress(int $limit = self::BATCH_SIZE): array
    {
        $days = (int) $this->getSettings()['compress_after_days'];
        $conversations = Conversation::whereNotNull('archived_at')
            ->whereNull('compressed_at')
            ->where('archived_at', '<', now()->subDays($days))
            ->limit($limit)
            ->get();

        $compressed = 0;
        $skipped = 0;
        $totalMessages = 0;
        $totalBytesSaved = 0;

        foreach ($conversations as $conversation) {
            $result = $this->compress($conversation);
            if ($result['success']) {
                $compressed++;
                $totalMessages += $result['messages_compressed'];
                $totalBytesSaved += $result['bytes_saved'];
            } else {
                $skipped++;
            }
        }

        return [
            'compressed' => $compressed,
            'skipped' => $skipped,
            'total_messages' => $totalMessages,
            'total_bytes_saved' => $totalBytesSaved,
            'total' => $conversations->count(),
            'message' => "{$compressed} conversation(s) compressed, {$skipped} skipped.",
        ];
    }

    public function restore(Conversation $conversation): array
    {
        if (! $conversation->compressed_at) {
            return ['success' => false, 'message' => 'Conversation is not compressed.'];
        }

        $filename = "archived-conversations/conversation_{$conversation->id}.json";
        $disk = Storage::disk($this->archiveDisk());

        if (! $disk->exists($filename)) {
            return ['success' => false, 'message' => 'Archive file not found.'];
        }

        $data = json_decode($disk->get($filename), true);
        $messages = $data['messages'] ?? [];
        $restored = DB::transaction(function () use ($conversation, $messages) {
            $count = 0;
            foreach ($messages as $msgData) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'facebook_page_id' => $conversation->facebook_page_id,
                    'customer_identity_id' => $msgData['metadata']['customer_identity_id'] ?? $conversation->customer_identity_id,
                    'sent_by' => $msgData['sent_by'] ?? null,
                    'direction' => $msgData['direction'],
                    'message_type' => $msgData['message_type'],
                    'body' => $msgData['body'],
                    'attachments' => $msgData['attachments'] ?? null,
                    'metadata' => $msgData['metadata'] ?? null,
                    'sent_at' => $msgData['sent_at'] ?? null,
                ]);
                $count++;
            }

            $conversation->forceFill([
                'compressed_at' => null,
                'status' => Conversation::STATUS_RESOLVED,
                'archived_at' => null,
            ])->save();

            return $count;
        });

        $disk->delete($filename);

        return [
            'success' => true,
            'conversation_id' => $conversation->id,
            'messages_restored' => $restored,
            'message' => "Conversation #{$conversation->id} restored ({$restored} messages).",
        ];
    }

    public function getStats(): array
    {
        $totalConversations = Conversation::count();
        $archivedCount = Conversation::archived()->count();
        $compressedCount = Conversation::compressed()->count();
        $archivableCount = Conversation::archivable((int) $this->getSettings()['archive_after_days'])->count();
        $compressibleCount = Conversation::whereNotNull('archived_at')
            ->whereNull('compressed_at')
            ->where('archived_at', '<', now()->subDays((int) $this->getSettings()['compress_after_days']))
            ->count();

        $totalMessagesInArchived = Conversation::archived()->sum('message_count');
        $compressedMessages = Conversation::compressed()->sum('message_count');

        $oldestArchived = Conversation::archived()->oldest('archived_at')->value('archived_at');
        $newestArchived = Conversation::archived()->latest('archived_at')->value('archived_at');

        $recentArchives = Conversation::archived()
            ->with(['customer:id,name,facebook_name', 'facebookPage:id,name'])
            ->latest('archived_at')
            ->limit(10)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'status' => $c->status,
                'customer_name' => $c->customer?->name ?? $c->customer?->facebook_name ?? 'Unknown',
                'page_name' => $c->facebookPage?->name ?? 'N/A',
                'message_count' => $c->message_count,
                'archived_at' => $c->archived_at?->toIso8601String(),
                'compressed_at' => $c->compressed_at?->toIso8601String(),
                'is_compressed' => (bool) $c->compressed_at,
            ]);

        return [
            'total_conversations' => $totalConversations,
            'archived_count' => $archivedCount,
            'compressed_count' => $compressedCount,
            'archivable_count' => $archivableCount,
            'compressible_count' => $compressibleCount,
            'total_messages_in_archived' => $totalMessagesInArchived,
            'compressed_messages' => $compressedMessages,
            'oldest_archived_at' => $oldestArchived?->toIso8601String(),
            'newest_archived_at' => $newestArchived?->toIso8601String(),
            'recent_archives' => $recentArchives,
            'settings' => $this->getSettings(),
        ];
    }

    public function getSettings(): array
    {
        return [
            'archive_after_days' => (int) SiteSetting::get('archive_after_days', self::DEFAULT_ARCHIVE_DAYS),
            'compress_after_days' => (int) SiteSetting::get('compress_after_days', self::DEFAULT_COMPRESS_DAYS),
            'auto_archive_enabled' => SiteSetting::get('auto_archive_enabled', '1') === '1',
            'auto_compress_enabled' => SiteSetting::get('auto_compress_enabled', '1') === '1',
            'batch_size' => (int) SiteSetting::get('archive_batch_size', self::BATCH_SIZE),
        ];
    }

    public function updateSettings(array $settings): array
    {
        $allowed = ['archive_after_days', 'compress_after_days', 'auto_archive_enabled', 'auto_compress_enabled', 'archive_batch_size'];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $settings)) {
                SiteSetting::set($key, (string) $settings[$key]);
            }
        }

        return $this->getSettings();
    }

    private function buildMessageSummary($messages): array
    {
        $inbound = $messages->where('direction', 'inbound');
        $outbound = $messages->where('direction', 'outbound');

        return [
            'total' => $messages->count(),
            'inbound_count' => $inbound->count(),
            'outbound_count' => $outbound->count(),
            'first_message_at' => $messages->first()?->sent_at?->toIso8601String(),
            'last_message_at' => $messages->last()?->sent_at?->toIso8601String(),
            'participants' => $messages->pluck('sent_by')->filter()->unique()->values()->all(),
            'has_attachments' => $messages->contains(fn ($m) => ! empty($m->attachments)),
        ];
    }

    private function archiveDisk(): string
    {
        return (string) SiteSetting::get('archive_storage_disk', 'local');
    }
}
