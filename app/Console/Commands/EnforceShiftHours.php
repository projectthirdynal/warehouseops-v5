<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnforceShiftHours extends Command
{
    protected $signature = 'shop:enforce-shift-hours';

    protected $description = 'Auto-toggle agent availability based on shift start/end times.';

    public function handle(): int
    {
        $nowTime = now()->format('H:i');

        $agents = User::query()
            ->where('users.is_active', true)
            ->whereIn('users.role', ['agent', 'supervisor'])
            ->join('agent_profiles', 'agent_profiles.user_id', '=', 'users.id')
            ->whereNotNull('agent_profiles.shift_start')
            ->whereNotNull('agent_profiles.shift_end')
            ->selectRaw('users.id, users.name, agent_profiles.is_available, agent_profiles.shift_start, agent_profiles.shift_end')
            ->get();

        $activated = 0;
        $deactivated = 0;

        foreach ($agents as $agent) {
            $startTime = now()->parse($agent->shift_start)->format('H:i');
            $endTime = now()->parse($agent->shift_end)->format('H:i');

            // Handle overnight shifts (e.g. 22:00 - 06:00)
            $inShift = $endTime < $startTime
                ? ($nowTime >= $startTime || $nowTime < $endTime)
                : ($nowTime >= $startTime && $nowTime < $endTime);

            if ($inShift && ! $agent->is_available) {
                AgentProfile::where('user_id', $agent->id)->update([
                    'is_available' => true,
                ]);
                $activated++;
                Log::info("Shift enforcement: activated {$agent->name} (ID: {$agent->id}) — shift started at {$startTime}.");
            } elseif (! $inShift && $agent->is_available) {
                AgentProfile::where('user_id', $agent->id)->update([
                    'is_available' => false,
                ]);
                $deactivated++;
                Log::info("Shift enforcement: deactivated {$agent->name} (ID: {$agent->id}) — shift ended at {$endTime}.");
            }
        }

        $this->info("Shift enforcement: {$activated} activated, {$deactivated} deactivated, {$agents->count()} agents with shift schedules.");

        return Command::SUCCESS;
    }
}
