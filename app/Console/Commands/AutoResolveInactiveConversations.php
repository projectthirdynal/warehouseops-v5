<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\ConversationStatusHistory;
use App\Events\ConversationStatusChanged;
use App\Models\SiteSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AutoResolveInactiveConversations extends Command
{
    protected $signature = 'shop:auto-resolve-inactive';
    protected $description = 'Auto-resolve conversations that have been inactive beyond configured thresholds.';

    public function handle(): int
    {
        $thresholds = $this->getThresholds();
        $now = now();
        $resolvedCount = 0;

        foreach ($thresholds as $status => $hours) {
            if ($hours === null || $hours <= 0) {
                continue;
            }

            $cutoff = $now->copy()->subHours($hours);

            $conversations = Conversation::query()
                ->where('status', $status)
                ->whereNull('merged_into_id')
                ->where(function ($q) use ($cutoff) {
                    $q->whereNull('last_message_at')
                        ->orWhere('last_message_at', '<', $cutoff);
                })
                ->whereDoesntHave('messages', function ($q) use ($cutoff) {
                    $q->where('direction', 'inbound')
                        ->where('sent_at', '>=', $cutoff);
                })
                ->get(['id', 'status', 'last_message_at', 'created_at']);

            foreach ($conversations as $conversation) {
                $conversation->forceFill([
                    'status' => Conversation::STATUS_RESOLVED,
                    'resolved_at' => $now,
                    'resolution_time_seconds' => $conversation->created_at
                        ? (int) $now->diffInSeconds($conversation->created_at)
                        : null,
                ])->save();

                ConversationStatusHistory::create([
                    'conversation_id' => $conversation->id,
                    'from_status' => $status,
                    'to_status' => Conversation::STATUS_RESOLVED,
                    'changed_by_id' => null,
                    'changed_by_role' => 'system',
                    'reason' => 'auto_inactivity',
                ]);

                ConversationStatusChanged::dispatch($conversation, $status, Conversation::STATUS_RESOLVED, null, 'auto_inactivity');

                $resolvedCount++;
            }

            $this->info("Status '{$status}': resolved {$conversations->count()} conversation(s) inactive > {$hours}h.");
        }

        if ($resolvedCount > 0) {
            Log::info("Auto-resolved {$resolvedCount} inactive conversation(s).");
        }

        $this->info("Total auto-resolved: {$resolvedCount}");

        return Command::SUCCESS;
    }

    private function getThresholds(): array
    {
        $defaults = [
            Conversation::STATUS_NEW => 24,
            Conversation::STATUS_ASSIGNED => 72,
            Conversation::STATUS_AWAITING_CUSTOMER => 48,
        ];

        $overrides = SiteSetting::get('conversation_auto_resolve_hours');
        if ($overrides) {
            $decoded = json_decode($overrides, true);
            if (is_array($decoded)) {
                return array_merge($defaults, $decoded);
            }
        }

        return $defaults;
    }
}
