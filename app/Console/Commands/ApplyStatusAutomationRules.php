<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Modules\Shop\Models\Conversation;
use Modules\Shop\Models\ConversationStatusHistory;
use Modules\Shop\Models\PageStatusRule;
use App\Events\ConversationStatusChanged;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ApplyStatusAutomationRules extends Command
{
    protected $signature = 'shop:apply-status-rules';

    protected $description = 'Apply page-level status automation rules to conversations that meet inactivity thresholds.';

    public function handle(): int
    {
        $rules = PageStatusRule::query()
            ->where('is_active', true)
            ->where('inactivity_minutes', '>', 0)
            ->get();

        if ($rules->isEmpty()) {
            $this->info('No active status automation rules found.');

            return Command::SUCCESS;
        }

        $now = now();
        $appliedCount = 0;

        foreach ($rules as $rule) {
            $cutoff = $now->copy()->subMinutes($rule->inactivity_minutes);

            $conversations = Conversation::query()
                ->where('facebook_page_id', $rule->facebook_page_id)
                ->where('status', $rule->from_status)
                ->whereNull('merged_into_id')
                ->whereNull('resolved_at')
                ->where(function ($q) use ($cutoff) {
                    $q->whereNull('last_message_at')
                        ->orWhere('last_message_at', '<', $cutoff);
                })
                ->get();

            foreach ($conversations as $conversation) {
                $oldStatus = $conversation->status;

                $updates = ['status' => $rule->to_status];

                if ($rule->to_status === Conversation::STATUS_RESOLVED && ! $conversation->resolved_at) {
                    $updates['resolved_at'] = $now;
                    $updates['resolution_time_seconds'] = $conversation->created_at
                        ? (int) $now->diffInSeconds($conversation->created_at)
                        : null;
                }

                if ($rule->to_status !== Conversation::STATUS_RESOLVED && $conversation->resolved_at) {
                    $updates['resolved_at'] = null;
                    $updates['resolution_time_seconds'] = null;
                }

                $conversation->forceFill($updates)->save();

                ConversationStatusHistory::create([
                    'conversation_id' => $conversation->id,
                    'from_status' => $oldStatus,
                    'to_status' => $rule->to_status,
                    'changed_by_id' => null,
                    'changed_by_role' => 'system',
                    'reason' => 'status_automation_rule',
                ]);

                ConversationStatusChanged::dispatch($conversation, $oldStatus, $rule->to_status, null, 'status_automation_rule');

                $appliedCount++;
            }

            if ($conversations->isNotEmpty()) {
                $this->info("Rule #{$rule->id}: {$conversations->count()} conversation(s) transitioned {$rule->from_status} → {$rule->to_status} (page #{$rule->facebook_page_id}, inactivity > {$rule->inactivity_minutes}m).");
            }
        }

        if ($appliedCount > 0) {
            Log::info("Status automation: {$appliedCount} conversation(s) transitioned by rules.");
        }

        $this->info("Total rules applied: {$appliedCount}");

        return Command::SUCCESS;
    }
}
