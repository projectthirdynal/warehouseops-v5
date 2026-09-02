<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Shop\Models\Conversation;

class ConversationSlaService
{
    public function getStats(): array
    {
        $thresholds = Conversation::slaThresholds();
        $warningPercent = (int) SiteSetting::get('conversation_sla_warning_percent', (string) Conversation::SLA_WARNING_PERCENT);
        $today = today();
        $yesterday = today()->subDay();

        $active = Conversation::query()
            ->whereIn('status', Conversation::ACTIVE_STATUSES)
            ->whereNull('merged_into_id')
            ->get(['id', 'status', 'created_at', 'first_response_at', 'first_response_time_seconds', 'assigned_agent_id']);

        $breached = 0;
        $warning = 0;
        $ok = 0;
        $unresponded = 0;

        foreach ($active as $conv) {
            $threshold = $thresholds[$conv->status] ?? null;
            if ($threshold === null) {
                continue;
            }
            if (! $conv->first_response_at) {
                $unresponded++;
            }
            $startedAt = $conv->first_response_at ?? $conv->created_at;
            $elapsedMinutes = $startedAt ? (int) now()->diffInMinutes($startedAt) : 0;
            $warningAt = (int) ($threshold * $warningPercent / 100);
            if ($elapsedMinutes >= $threshold) {
                $breached++;
            } elseif ($elapsedMinutes >= $warningAt) {
                $warning++;
            } else {
                $ok++;
            }
        }

        $frStats = Conversation::query()
            ->whereDate('first_response_at', $today)
            ->whereNotNull('first_response_time_seconds')
            ->selectRaw('COUNT(*) as count, AVG(first_response_time_seconds) as avg_seconds, MIN(first_response_time_seconds) as min_seconds, MAX(first_response_time_seconds) as max_seconds')
            ->first();

        $resStats = Conversation::query()
            ->whereDate('resolved_at', $today)
            ->whereNotNull('resolution_time_seconds')
            ->selectRaw('COUNT(*) as count, AVG(resolution_time_seconds) as avg_seconds, MIN(resolution_time_seconds) as min_seconds, MAX(resolution_time_seconds) as max_seconds')
            ->first();

        $yesterdayFr = Conversation::query()
            ->whereDate('first_response_at', $yesterday)
            ->whereNotNull('first_response_time_seconds')
            ->avg('first_response_time_seconds');

        $yesterdayRes = Conversation::query()
            ->whereDate('resolved_at', $yesterday)
            ->whereNotNull('resolution_time_seconds')
            ->avg('resolution_time_seconds');

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $dayFr = Conversation::query()
                ->whereDate('first_response_at', $date)
                ->whereNotNull('first_response_time_seconds')
                ->selectRaw('AVG(first_response_time_seconds) as avg_fr, COUNT(*) as responded')
                ->first();
            $dayRes = Conversation::query()
                ->whereDate('resolved_at', $date)
                ->whereNotNull('resolution_time_seconds')
                ->selectRaw('AVG(resolution_time_seconds) as avg_res, COUNT(*) as resolved')
                ->first();
            $dayCreated = Conversation::query()->whereDate('created_at', $date)->count();
            $trend[] = [
                'date' => $date->toDateString(),
                'avg_first_response_seconds' => $dayFr?->avg_fr ? (int) $dayFr->avg_fr : null,
                'responded' => (int) ($dayFr?->responded ?? 0),
                'avg_resolution_seconds' => $dayRes?->avg_res ? (int) $dayRes->avg_res : null,
                'resolved' => (int) ($dayRes?->resolved ?? 0),
                'created' => $dayCreated,
            ];
        }

        $agentFr = Conversation::query()
            ->whereDate('first_response_at', $today)
            ->whereNotNull('first_response_time_seconds')
            ->whereNotNull('assigned_agent_id')
            ->selectRaw('assigned_agent_id, AVG(first_response_time_seconds) as avg_fr, COUNT(*) as count')
            ->groupBy('assigned_agent_id')
            ->orderBy('avg_fr')
            ->limit(10)
            ->get();

        $agentNames = User::query()->whereIn('id', $agentFr->pluck('assigned_agent_id'))->pluck('name', 'id');
        $agentStats = [];
        foreach ($agentFr as $row) {
            $agentStats[] = [
                'agent_id' => $row->assigned_agent_id,
                'agent_name' => $agentNames[$row->assigned_agent_id] ?? "Agent #{$row->assigned_agent_id}",
                'avg_first_response_seconds' => (int) $row->avg_fr,
                'responded_count' => (int) $row->count,
            ];
        }

        return [
            'active_total' => $active->count(),
            'breached' => $breached,
            'warning' => $warning,
            'ok' => $ok,
            'unresponded' => $unresponded,
            'breach_rate' => $active->count() > 0 ? round(($breached / $active->count()) * 100, 1) : 0,
            'first_response' => [
                'count' => (int) ($frStats?->count ?? 0),
                'avg_seconds' => $frStats?->avg_seconds ? (int) $frStats->avg_seconds : null,
                'min_seconds' => $frStats?->min_seconds ? (int) $frStats->min_seconds : null,
                'max_seconds' => $frStats?->max_seconds ? (int) $frStats->max_seconds : null,
                'yesterday_avg_seconds' => $yesterdayFr ? (int) $yesterdayFr : null,
            ],
            'resolution' => [
                'count' => (int) ($resStats?->count ?? 0),
                'avg_seconds' => $resStats?->avg_seconds ? (int) $resStats->avg_seconds : null,
                'min_seconds' => $resStats?->min_seconds ? (int) $resStats->min_seconds : null,
                'max_seconds' => $resStats?->max_seconds ? (int) $resStats->max_seconds : null,
                'yesterday_avg_seconds' => $yesterdayRes ? (int) $yesterdayRes : null,
            ],
            'trend' => $trend,
            'agent_performance' => $agentStats,
            'thresholds' => $thresholds,
            'warning_percent' => $warningPercent,
        ];
    }

    public function getSettings(): array
    {
        return [
            'thresholds' => Conversation::slaThresholds(),
            'warning_percent' => (int) SiteSetting::get('conversation_sla_warning_percent', (string) Conversation::SLA_WARNING_PERCENT),
            'breach_notifications' => SiteSetting::get('conversation_sla_breach_notifications', '1') === '1',
            'breach_notify_channel' => SiteSetting::get('conversation_sla_breach_notify_channel', 'log'),
        ];
    }

    public function updateSettings(array $data): array
    {
        if (isset($data['thresholds']) && is_array($data['thresholds'])) {
            $cleaned = [];
            foreach ($data['thresholds'] as $status => $minutes) {
                if (in_array($status, Conversation::STATUSES, true)) {
                    $cleaned[$status] = $minutes === null ? null : max(1, (int) $minutes);
                }
            }
            SiteSetting::set('conversation_sla_thresholds', json_encode($cleaned));
        }
        if (isset($data['warning_percent'])) {
            SiteSetting::set('conversation_sla_warning_percent', (string) max(50, min(99, (int) $data['warning_percent'])));
        }
        if (isset($data['breach_notifications'])) {
            SiteSetting::set('conversation_sla_breach_notifications', $data['breach_notifications'] ? '1' : '0');
        }
        if (isset($data['breach_notify_channel'])) {
            SiteSetting::set('conversation_sla_breach_notify_channel', $data['breach_notify_channel']);
        }

        return $this->getSettings();
    }

    public function getBreachedConversations(int $limit = 50): array
    {
        $thresholds = Conversation::slaThresholds();
        $conversations = Conversation::query()
            ->whereIn('status', Conversation::ACTIVE_STATUSES)
            ->whereNull('merged_into_id')
            ->with(['facebookPage:id,page_name', 'assignedAgent:id,name', 'customer:id,name', 'identity:id,display_name'])
            ->get()
            ->filter(function (Conversation $conv) use ($thresholds) {
                $threshold = $thresholds[$conv->status] ?? null;
                if ($threshold === null) {
                    return false;
                }
                $startedAt = $conv->first_response_at ?? $conv->created_at;
                $elapsedMinutes = $startedAt ? (int) now()->diffInMinutes($startedAt) : 0;

                return $elapsedMinutes >= $threshold;
            })
            ->sortByDesc(function (Conversation $conv) use ($thresholds) {
                $threshold = $thresholds[$conv->status] ?? 1;
                $startedAt = $conv->first_response_at ?? $conv->created_at;
                $elapsedMinutes = $startedAt ? (int) now()->diffInMinutes($startedAt) : 0;

                return $elapsedMinutes - $threshold;
            })
            ->take($limit);

        return $conversations->map(function (Conversation $conv) use ($thresholds) {
            $threshold = $thresholds[$conv->status] ?? null;
            $startedAt = $conv->first_response_at ?? $conv->created_at;
            $elapsedMinutes = $startedAt ? (int) now()->diffInMinutes($startedAt) : 0;

            return [
                'id' => $conv->id,
                'status' => $conv->status,
                'customer_name' => $conv->customer?->name ?? $conv->identity?->display_name ?? 'Unknown',
                'page_name' => $conv->facebookPage?->page_name,
                'agent_name' => $conv->assignedAgent?->name,
                'elapsed_minutes' => $elapsedMinutes,
                'threshold_minutes' => $threshold,
                'over_minutes' => $elapsedMinutes - ($threshold ?? 0),
                'first_response_at' => $conv->first_response_at?->toIso8601String(),
                'created_at' => $conv->created_at?->toIso8601String(),
                'last_message_preview' => $conv->last_message_preview,
            ];
        })->values()->toArray();
    }

    public function checkBreachAlerts(): int
    {
        if (SiteSetting::get('conversation_sla_breach_notifications', '1') !== '1') {
            return 0;
        }

        $breached = $this->getBreachedConversations(100);
        $count = count($breached);

        if ($count > 0) {
            $channel = SiteSetting::get('conversation_sla_breach_notify_channel', 'log');
            if ($channel === 'log') {
                Log::warning("SLA breach alert: {$count} conversations breached thresholds", [
                    'breached_count' => $count,
                    'top_breaches' => array_slice($breached, 0, 5),
                ]);
            }
        }

        return $count;
    }
}
