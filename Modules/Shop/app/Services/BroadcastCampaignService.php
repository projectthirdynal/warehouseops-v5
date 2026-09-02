<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shop\Models\BroadcastCampaign;
use Modules\Shop\Models\BroadcastRecipient;
use Modules\Shop\Models\BroadcastVariant;
use Modules\Shop\Models\Conversation;
use Modules\Shop\Models\Message;

class BroadcastCampaignService
{
    public function __construct(
        private readonly FacebookConnectorService $facebookConnector,
    ) {}

    public function previewRecipients(array $targeting): array
    {
        $query = $this->buildTargetingQuery($targeting);
        $total = $query->count();

        $sample = $query->with(['customer:id,name', 'facebookPage:id,page_name', 'identity:id,display_name'])
            ->limit(10)
            ->get()
            ->map(fn (Conversation $conv) => [
                'conversation_id' => $conv->id,
                'customer_name' => $conv->customer?->name ?? $conv->identity?->display_name ?? 'Unknown',
                'page_name' => $conv->facebookPage?->page_name,
                'status' => $conv->status,
            ]);

        return [
            'total' => $total,
            'sample' => $sample,
        ];
    }

    public function createCampaign(array $data, int $userId): BroadcastCampaign
    {
        return DB::transaction(function () use ($data, $userId) {
            $campaign = BroadcastCampaign::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'facebook_page_id' => $data['facebook_page_id'] ?? null,
                'status' => $data['scheduled_at'] ? 'scheduled' : 'draft',
                'targeting' => $data['targeting'] ?? null,
                'split_type' => $data['split_type'] ?? 'single',
                'split_percentage' => $data['split_percentage'] ?? 50,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['variants'] as $variantData) {
                $campaign->variants()->create([
                    'label' => $variantData['label'],
                    'body' => $variantData['body'],
                    'quick_replies' => $variantData['quick_replies'] ?? null,
                ]);
            }

            return $campaign;
        });
    }

    public function sendCampaign(BroadcastCampaign $campaign): array
    {
        if ($campaign->status === 'sending' || $campaign->status === 'completed') {
            return ['error' => 'Campaign already sent or in progress'];
        }

        $targeting = $campaign->targeting ?? [];
        $conversations = $this->buildTargetingQuery($targeting)->get();
        $variants = $campaign->variants;

        if ($variants->isEmpty()) {
            return ['error' => 'No message variants defined'];
        }

        $campaign->forceFill([
            'status' => 'sending',
            'started_at' => now(),
            'total_recipients' => $conversations->count(),
        ])->save();

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($conversations as $index => $conversation) {
            $variant = $this->selectVariant($variants, $index, $campaign->split_type, $campaign->split_percentage);

            $recipient = BroadcastRecipient::create([
                'broadcast_campaign_id' => $campaign->id,
                'broadcast_variant_id' => $variant->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $conversation->customer_id,
                'status' => 'pending',
            ]);

            if (! $conversation->facebookPage?->page_access_token || ! $conversation->identity?->provider_user_id) {
                $recipient->forceFill(['status' => 'skipped', 'error_message' => 'Missing page token or PSID'])->save();
                $skipped++;

                continue;
            }

            try {
                $delivery = $this->facebookConnector->sendMessage(
                    $conversation->facebookPage,
                    $conversation->identity->provider_user_id,
                    $variant->body
                );

                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'facebook_page_id' => $conversation->facebook_page_id,
                    'customer_identity_id' => $conversation->customer_identity_id,
                    'external_message_id' => $delivery['message_id'] ?? ('local-'.str()->uuid()),
                    'direction' => 'outbound',
                    'message_type' => 'text',
                    'body' => $variant->body,
                    'raw_payload' => $delivery,
                    'sent_at' => now(),
                    'send_status' => 'sent',
                    'retry_count' => 0,
                ]);

                $conversation->forceFill([
                    'last_message_preview' => $variant->body,
                    'last_message_at' => now(),
                    'draft_body' => null,
                ])->save();

                $recipient->forceFill([
                    'status' => 'sent',
                    'message_id' => $message->id,
                    'sent_at' => now(),
                ])->save();

                $variant->increment('sent_count');
                $sent++;
            } catch (\Throwable $e) {
                $recipient->forceFill(['status' => 'failed', 'error_message' => $e->getMessage()])->save();
                $variant->increment('failed_count');
                $failed++;
            }
        }

        foreach ($variants as $variant) {
            $variant->forceFill(['recipient_count' => $variant->recipients()->count()])->save();
        }

        $campaign->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'sent_count' => $sent,
            'failed_count' => $failed,
        ])->save();

        return [
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'total' => $conversations->count(),
        ];
    }

    public function cancelCampaign(BroadcastCampaign $campaign): bool
    {
        if (in_array($campaign->status, ['completed', 'cancelled'])) {
            return false;
        }

        $campaign->forceFill(['status' => 'cancelled'])->save();

        return true;
    }

    public function getStats(): array
    {
        $total = BroadcastCampaign::count();
        $completed = BroadcastCampaign::where('status', 'completed')->count();
        $scheduled = BroadcastCampaign::where('status', 'scheduled')->count();
        $draft = BroadcastCampaign::where('status', 'draft')->count();
        $sending = BroadcastCampaign::where('status', 'sending')->count();

        $totalRecipients = BroadcastCampaign::sum('total_recipients');
        $totalSent = BroadcastCampaign::sum('sent_count');
        $totalReplied = BroadcastCampaign::sum('replied_count');
        $totalFailed = BroadcastCampaign::sum('failed_count');

        $recentCampaigns = BroadcastCampaign::with(['variants', 'facebookPage:id,page_name'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (BroadcastCampaign $c) => $this->formatCampaign($c));

        $avgReplyRate = $totalSent > 0 ? round(($totalReplied / $totalSent) * 100, 1) : 0;

        return [
            'total_campaigns' => $total,
            'completed' => $completed,
            'scheduled' => $scheduled,
            'draft' => $draft,
            'sending' => $sending,
            'total_recipients' => $totalRecipients,
            'total_sent' => $totalSent,
            'total_replied' => $totalReplied,
            'total_failed' => $totalFailed,
            'avg_reply_rate' => $avgReplyRate,
            'recent_campaigns' => $recentCampaigns,
        ];
    }

    public function getCampaign(BroadcastCampaign $campaign): array
    {
        $campaign->load(['variants', 'recipients.conversation.customer:id,name', 'recipients.conversation.facebookPage:id,page_name', 'facebookPage:id,page_name', 'creator:id,name']);

        return $this->formatCampaignDetail($campaign);
    }

    public function listCampaigns(int $limit = 20): array
    {
        return BroadcastCampaign::with(['variants', 'facebookPage:id,page_name'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (BroadcastCampaign $c) => $this->formatCampaign($c))
            ->toArray();
    }

    private function buildTargetingQuery(array $targeting)
    {
        $query = Conversation::query()
            ->whereNull('merged_into_id')
            ->whereIn('status', Conversation::ACTIVE_STATUSES)
            ->with(['facebookPage', 'identity', 'customer']);

        if (! empty($targeting['page_id'])) {
            $query->where('facebook_page_id', $targeting['page_id']);
        }

        if (! empty($targeting['assigned_agent_id'])) {
            $query->where('assigned_agent_id', $targeting['assigned_agent_id']);
        }

        if (! empty($targeting['status'])) {
            $query->where('status', $targeting['status']);
        }

        if (! empty($targeting['tags'])) {
            $query->whereHas('customer', function ($q) use ($targeting) {
                foreach ($targeting['tags'] as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }
            });
        }

        if (! empty($targeting['risk_level'])) {
            $query->whereHas('customer', function ($q) use ($targeting) {
                $q->where('risk_level', $targeting['risk_level']);
            });
        }

        if (! empty($targeting['opt_in_only'])) {
            $query->whereHas('customer', function ($q) {
                $q->where('marketing_opt_out', false)->orWhereNull('marketing_opt_out');
            });
        }

        if (! empty($targeting['has_ordered'])) {
            $query->whereHas('customer', function ($q) {
                $q->where('total_orders', '>', 0);
            });
        }

        if (! empty($targeting['min_order_count'])) {
            $query->whereHas('customer', function ($q) use ($targeting) {
                $q->where('total_orders', '>=', (int) $targeting['min_order_count']);
            });
        }

        return $query;
    }

    private function selectVariant($variants, int $index, string $splitType, int $splitPercentage): BroadcastVariant
    {
        if ($splitType === 'single' || $variants->count() === 1) {
            return $variants->first();
        }

        $mod = $index % 100;

        return $mod < $splitPercentage ? $variants->first() : $variants->last();
    }

    private function formatCampaign(BroadcastCampaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'split_type' => $c->split_type,
            'page_name' => $c->facebookPage?->page_name,
            'total_recipients' => $c->total_recipients,
            'sent_count' => $c->sent_count,
            'replied_count' => $c->replied_count,
            'failed_count' => $c->failed_count,
            'reply_rate' => $c->sent_count > 0 ? round(($c->replied_count / $c->sent_count) * 100, 1) : 0,
            'created_at' => $c->created_at?->toIso8601String(),
            'completed_at' => $c->completed_at?->toIso8601String(),
            'variants' => $c->variants->map(fn ($v) => [
                'id' => $v->id,
                'label' => $v->label,
                'sent_count' => $v->sent_count,
                'replied_count' => $v->replied_count,
                'reply_rate' => $v->sent_count > 0 ? round(($v->replied_count / $v->sent_count) * 100, 1) : 0,
            ]),
        ];
    }

    private function formatCampaignDetail(BroadcastCampaign $c): array
    {
        $base = $this->formatCampaign($c);
        $base['description'] = $c->description;
        $base['targeting'] = $c->targeting;
        $base['split_percentage'] = $c->split_percentage;
        $base['scheduled_at'] = $c->scheduled_at?->toIso8601String();
        $base['started_at'] = $c->started_at?->toIso8601String();
        $base['creator_name'] = $c->creator?->name;
        $base['variants'] = $c->variants->map(fn ($v) => [
            'id' => $v->id,
            'label' => $v->label,
            'body' => $v->body,
            'quick_replies' => $v->quick_replies,
            'recipient_count' => $v->recipient_count,
            'sent_count' => $v->sent_count,
            'delivered_count' => $v->delivered_count,
            'read_count' => $v->read_count,
            'replied_count' => $v->replied_count,
            'failed_count' => $v->failed_count,
            'reply_rate' => $v->sent_count > 0 ? round(($v->replied_count / $v->sent_count) * 100, 1) : 0,
        ]);

        return $base;
    }
}
