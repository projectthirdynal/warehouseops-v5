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

    protected $description = 'Detect idle agents and auto-set them unavailable.';

    public function handle(): int
    {
        $agents = User::query()
            ->where('users.is_active', true)
            ->whereIn('users.role', ['agent', 'supervisor'])
            ->where('agent_profiles.is_available', true)
            ->join('agent_profiles', 'agent_profiles.user_id', '=', 'users.id')
            ->selectRaw('users.id, users.name, agent_profiles.last_seen_at, agent_profiles.idle_threshold_minutes')
            ->get();

        $idleCount = 0;

        foreach ($agents as $agent) {
            $threshold = $agent->idle_threshold_minutes ?? 15;

            if (! $agent->last_seen_at) {
                $idleCount++;
                $this->setUnavailable($agent->id, $agent->name, 'no last_seen_at', $threshold);

                continue;
            }

            $idleAt = $agent->last_seen_at->copy()->addMinutes($threshold);

            if (now()->gt($idleAt)) {
                $minutesIdle = (int) $agent->last_seen_at->diffInMinutes(now());
                $idleCount++;
                $this->setUnavailable($agent->id, $agent->name, "idle for {$minutesIdle} min", $threshold);
            }
        }

        $this->info("Checked {$agents->count()} agent(s), set {$idleCount} unavailable.");

        return Command::SUCCESS;
    }

    private function setUnavailable(int $agentId, string $agentName, string $reason, int $threshold): void
    {
        AgentProfile::where('user_id', $agentId)->update([
            'is_available' => false,
        ]);

        Log::warning("Auto-unavailable: {$agentName} (ID: {$agentId}) — {$reason} (threshold: {$threshold} min).");
    }
}
