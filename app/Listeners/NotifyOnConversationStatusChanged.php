<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ConversationStatusChanged;
use App\Models\User;
use App\Notifications\ConversationStatusChangedNotification;
use Illuminate\Support\Facades\Log;

class NotifyOnConversationStatusChanged
{
    public function handle(ConversationStatusChanged $event): void
    {
        $changedByName = $event->changedBy?->name ?? 'System';

        $recipients = collect();

        if ($event->conversation->assigned_agent_id) {
            $agent = User::find($event->conversation->assigned_agent_id);
            if ($agent) {
                $recipients->push($agent);
            }
        }

        $supervisors = User::query()
            ->whereIn('role', ['supervisor', 'admin', 'superadmin'])
            ->where('is_active', true)
            ->get();

        $recipients = $recipients->merge($supervisors)->unique('id');

        foreach ($recipients as $user) {
            if ($event->changedBy && $user->id === $event->changedBy->id) {
                continue;
            }

            $user->notify(new ConversationStatusChangedNotification(
                $event->conversation,
                $event->fromStatus,
                $event->toStatus,
                $changedByName,
                $event->reason,
            ));
        }

        Log::info("ConversationStatusChanged: #{$event->conversation->id} {$event->fromStatus}→{$event->toStatus} by {$changedByName}, notified {$recipients->count()} user(s).");
    }
}
