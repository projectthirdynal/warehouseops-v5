<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shop\Models\Conversation;

class ConversationMergePreviewService
{
    public function preview(Conversation $target, Conversation $source): array
    {
        $targetMessages = $target->messages()->count();
        $sourceMessages = $source->messages()->count();

        $targetTags = $target->tags()->pluck('tags.id', 'tags.name')->toArray();
        $sourceTags = $source->tags()->pluck('tags.id', 'tags.name')->toArray();

        $tagsOnlyInSource = array_keys(array_diff_key($sourceTags, $targetTags));
        $tagsOnlyInTarget = array_keys(array_diff_key($targetTags, $sourceTags));
        $commonTags = array_keys(array_intersect_key($sourceTags, $targetTags));

        $targetCustomer = $target->customer;
        $sourceCustomer = $source->customer;

        $targetIdentity = $target->identity;
        $sourceIdentity = $source->identity;

        $conflicts = $this->detectConflicts($target, $source);

        $sourceAssignmentHistory = $source->assignmentHistories()
            ->with(['fromAgent:id,name', 'toAgent:id,name', 'assignedBy:id,name'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'from_agent' => $h->fromAgent?->name,
                'to_agent' => $h->toAgent?->name ?? 'Unassigned',
                'assigned_by' => $h->assignedBy?->name,
                'reason' => $h->reason,
                'created_at' => $h->created_at?->toIso8601String(),
            ]);

        $sourceStatusHistory = $source->statusHistories()
            ->with(['changedBy:id,name'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'changed_by' => $h->changedBy?->name ?? 'System',
                'created_at' => $h->created_at?->toIso8601String(),
            ]);

        $recentSourceMessages = $source->messages()
            ->latest('id')
            ->limit(5)
            ->get(['id', 'direction', 'body', 'created_at'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'body' => mb_strimwidth($m->body ?? '', 0, 120, '...'),
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        return [
            'target' => [
                'id' => $target->id,
                'status' => $target->status,
                'customer_name' => $targetCustomer?->name,
                'identity_name' => $targetIdentity?->display_name,
                'assigned_agent_id' => $target->assigned_agent_id,
                'message_count' => $targetMessages,
                'last_message_at' => $target->last_message_at?->toIso8601String(),
                'last_message_preview' => $target->last_message_preview,
                'unread_count' => $target->unread_count ?? 0,
                'tags' => array_keys($targetTags),
            ],
            'source' => [
                'id' => $source->id,
                'status' => $source->status,
                'customer_name' => $sourceCustomer?->name,
                'identity_name' => $sourceIdentity?->display_name,
                'assigned_agent_id' => $source->assigned_agent_id,
                'message_count' => $sourceMessages,
                'last_message_at' => $source->last_message_at?->toIso8601String(),
                'last_message_preview' => $source->last_message_preview,
                'unread_count' => $source->unread_count ?? 0,
                'tags' => array_keys($sourceTags),
                'recent_messages' => $recentSourceMessages,
                'assignment_history' => $sourceAssignmentHistory,
                'status_history' => $sourceStatusHistory,
            ],
            'merge_summary' => [
                'total_messages_after' => $targetMessages + $sourceMessages,
                'tags_to_add' => $tagsOnlyInSource,
                'tags_already_present' => $commonTags,
                'tags_only_in_target' => $tagsOnlyInTarget,
                'unread_after' => ($target->unread_count ?? 0) + ($source->unread_count ?? 0),
                'source_will_be_archived' => true,
                'source_merged_into_id' => $target->id,
            ],
            'conflicts' => $conflicts,
            'can_merge' => empty($conflicts),
        ];
    }

    public function executeMerge(Conversation $target, Conversation $source): array
    {
        if ($source->id === $target->id) {
            return ['success' => false, 'error' => 'Cannot merge a conversation into itself.'];
        }

        if ($target->merged_into_id) {
            return ['success' => false, 'error' => 'Target conversation is already merged into another.'];
        }

        if ($source->merged_into_id) {
            return ['success' => false, 'error' => 'Source conversation has already been merged.'];
        }

        DB::transaction(function () use ($source, $target): void {
            $source->messages()->update(['conversation_id' => $target->id]);

            $source->assignmentHistories()->update(['conversation_id' => $target->id]);
            $source->statusHistories()->update(['conversation_id' => $target->id]);

            $sourceTagIds = $source->tags()->pluck('tags.id');
            $target->tags()->syncWithoutDetaching($sourceTagIds);

            $source->forceFill([
                'merged_into_id' => $target->id,
                'status' => 'archived',
                'archived_at' => now(),
            ])->save();

            if ($source->last_message_at && (! $target->last_message_at || $source->last_message_at > $target->last_message_at)) {
                $target->forceFill([
                    'last_message_at' => $source->last_message_at,
                    'last_message_preview' => $source->last_message_preview,
                ])->save();
            }

            if ($source->unread_count > 0) {
                $target->increment('unread_count', $source->unread_count);
            }
        });

        return [
            'success' => true,
            'merged_source_id' => $source->id,
            'target_id' => $target->id,
            'message' => "Conversation #{$source->id} merged into #{$target->id}.",
        ];
    }

    private function detectConflicts(Conversation $target, Conversation $source): array
    {
        $conflicts = [];

        if ($target->customer_id && $source->customer_id && $target->customer_id !== $source->customer_id) {
            $conflicts[] = [
                'type' => 'customer_mismatch',
                'message' => 'Conversations are linked to different customers.',
                'target_customer_id' => $target->customer_id,
                'source_customer_id' => $source->customer_id,
            ];
        }

        if ($target->facebook_page_id && $source->facebook_page_id && $target->facebook_page_id !== $source->facebook_page_id) {
            $conflicts[] = [
                'type' => 'page_mismatch',
                'message' => 'Conversations belong to different Facebook pages.',
                'target_page_id' => $target->facebook_page_id,
                'source_page_id' => $source->facebook_page_id,
            ];
        }

        if ($target->assigned_agent_id && $source->assigned_agent_id && $target->assigned_agent_id !== $source->assigned_agent_id) {
            $conflicts[] = [
                'type' => 'agent_mismatch',
                'message' => 'Conversations are assigned to different agents. Target agent will be retained.',
                'target_agent_id' => $target->assigned_agent_id,
                'source_agent_id' => $source->assigned_agent_id,
            ];
        }

        if ($source->status === 'resolved') {
            $conflicts[] = [
                'type' => 'source_resolved',
                'message' => 'Source conversation is already resolved. Merging may lose resolved context.',
            ];
        }

        return $conflicts;
    }
}
