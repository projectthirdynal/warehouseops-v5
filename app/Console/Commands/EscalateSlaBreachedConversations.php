<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Modules\Shop\Models\Conversation;
use Modules\Shop\Models\ConversationStatusHistory;
use App\Models\SiteSetting;
use App\Models\User;
use App\Notifications\ConversationEscalatedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class EscalateSlaBreachedConversations extends Command
{
    protected $signature = 'shop:escalate-sla-breached';

    protected $description = 'Escalate conversations with breached SLA thresholds — flag, notify supervisors, and optionally reassign.';

    public function handle(): int
    {
        $thresholds = Conversation::SLA_THRESHOLDS;
        $warningPercent = Conversation::SLA_WARNING_PERCENT;
        $now = now();
        $escalatedCount = 0;

        // Get escalation settings
        $autoReassign = SiteSetting::get('conversation_escalation_auto_reassign', '0') === '1';
        $notifyEnabled = SiteSetting::get('conversation_escalation_notify', '1') !== '0';

        foreach ($thresholds as $status => $thresholdMinutes) {
            if ($thresholdMinutes === null || $thresholdMinutes <= 0) {
                continue;
            }

            $breachCutoff = $now->copy()->subMinutes($thresholdMinutes);

            // Find conversations in this status that have exceeded the SLA threshold
            $conversations = Conversation::query()
                ->where('status', $status)
                ->whereNull('merged_into_id')
                ->whereNull('resolved_at')
                ->whereDoesntHave('statusHistories', function ($q) use ($breachCutoff) {
                    $q->where('to_status', $status)
                        ->where('created_at', '>=', $breachCutoff);
                })
                ->get();

            foreach ($conversations as $conversation) {
                // Compute elapsed time from latest status history entry
                $latestHistory = $conversation->statusHistories()->latest('id')->first();
                $startedAt = $latestHistory?->created_at ?? $conversation->updated_at;
                $elapsedMinutes = $startedAt ? (int) $now->diffInMinutes($startedAt) : 0;

                if ($elapsedMinutes < $thresholdMinutes) {
                    continue;
                }

                // Flag the conversation
                $conversation->forceFill([
                    'is_flagged' => true,
                    'flag_reason' => "SLA breached: {$elapsedMinutes}m in {$status} (threshold: {$thresholdMinutes}m)",
                    'flagged_at' => $conversation->flagged_at ?? $now,
                ])->save();

                // Optionally reassign to supervisor pool
                if ($autoReassign) {
                    $supervisor = User::query()
                        ->whereIn('role', ['supervisor', 'admin'])
                        ->where('is_active', true)
                        ->orderByRaw('active_conversations ASC')
                        ->first();

                    if ($supervisor && $supervisor->id !== $conversation->assigned_agent_id) {
                        $oldAgentId = $conversation->assigned_agent_id;
                        $conversation->forceFill(['assigned_agent_id' => $supervisor->id])->save();

                        Log::info("Escalation: conversation #{$conversation->id} reassigned from agent {$oldAgentId} to supervisor {$supervisor->name}.");
                    }
                }

                // Record status history for the escalation flag
                ConversationStatusHistory::create([
                    'conversation_id' => $conversation->id,
                    'from_status' => $status,
                    'to_status' => $status,
                    'changed_by_id' => null,
                    'changed_by_role' => 'system',
                    'reason' => 'sla_escalation',
                ]);

                // Notify supervisors
                if ($notifyEnabled) {
                    $supervisors = User::query()
                        ->whereIn('role', ['supervisor', 'admin', 'superadmin'])
                        ->where('is_active', true)
                        ->get();

                    Notification::send(
                        $supervisors,
                        new ConversationEscalatedNotification(
                            $conversation,
                            "SLA breached for status '{$status}'",
                            $elapsedMinutes,
                        )
                    );
                }

                $escalatedCount++;
                $this->info("Escalated conversation #{$conversation->id} ({$status}, {$elapsedMinutes}m elapsed, threshold {$thresholdMinutes}m).");
            }
        }

        if ($escalatedCount > 0) {
            Log::info("SLA escalation: {$escalatedCount} conversation(s) escalated.");
        }

        $this->info("Total escalated: {$escalatedCount}");

        return Command::SUCCESS;
    }
}
