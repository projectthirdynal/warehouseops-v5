<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckIdleAgents extends Command
{
    protected $signature = 'shop:check-idle-agents';
    protected $description = 'Check for idle agents and log alerts.';

    public function handle(): int
    {
        $activeStatuses = ['open', 'pending_details', 'for_confirmation', 'confirmed'];

        $agents = User::query()
            ->where('users.is_active', true)
            ->whereIn('users.role', ['agent', 'supervisor'])
            ->where('agent_profiles.is_available', true)
            ->join('agent_profiles', 'agent_profiles.user_id', '=', 'users.id')
            ->leftJoin('conversations', function ($join) use ($activeStatuses) {
                $join->on('conversations.assigned_agent_id', '=', 'users.id')
                    ->whereIn('conversations.status', $activeStatuses)
                    ->whereNull('conversations.merged_into_id');
            })
            ->groupBy('users.id', 'users.name', 'agent_profiles.last_seen_at', 'agent_profiles.idle_threshold_minutes')
            ->selectRaw('users.id, users.name, agent_profiles.last_seen_at, agent_profiles.idle_threshold_minutes, COUNT(conversations.id) as active_count')
            ->having('active_count', '>', 0)
            ->get();

        $idleCount = 0;

        foreach ($agents as $agent) {
            $threshold = $agent->idle_threshold_minutes ?? 15;

            if (! $agent->last_seen_at) {
                $idleCount++;
                Log::warning("Idle agent: {$agent->name} (ID: {$agent->id}) — no last_seen_at, {$agent->active_count} active conversation(s).");
                continue;
            }

            $idleAt = $agent->last_seen_at->copy()->addMinutes($threshold);

            if (now()->gt($idleAt)) {
                $minutesIdle = (int) $agent->last_seen_at->diffInMinutes(now());
                $idleCount++;
                Log::warning("Idle agent: {$agent->name} (ID: {$agent->id}) — idle for {$minutesIdle} min (threshold: {$threshold} min), {$agent->active_count} active conversation(s).");
            }
        }

        $this->info("Checked {$agents->count()} agent(s), found {$idleCount} idle.");

        return Command::SUCCESS;
    }
}
