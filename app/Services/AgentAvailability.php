<?php

namespace App\Services;

use App\Models\AgentProfile;
use Carbon\Carbon;

class AgentAvailability
{
    /**
     * Check if the agent is currently within their shift hours.
     */
    public function isWithinShift(int $agentId): bool
    {
        $profile = AgentProfile::where('user_id', $agentId)->first();
        if (! $profile) {
            return false;
        }

        // No shift defined = always available
        if (! $profile->shift_start || ! $profile->shift_end) {
            return true;
        }

        // Compare time-only to avoid timezone issues with stored time strings
        $nowTime = Carbon::now()->format('H:i');
        $startTime = Carbon::parse($profile->shift_start)->format('H:i');
        $endTime = Carbon::parse($profile->shift_end)->format('H:i');

        // Handle overnight shifts (e.g. 22:00 - 06:00)
        if ($endTime < $startTime) {
            return $nowTime >= $startTime || $nowTime < $endTime;
        }

        return $nowTime >= $startTime && $nowTime < $endTime;
    }

    /**
     * Check if the agent has opted out of a given region.
     */
    public function hasExcludedRegion(int $agentId, string $region): bool
    {
        $profile = AgentProfile::where('user_id', $agentId)->first();
        if (! $profile || empty($profile->excluded_regions)) {
            return false;
        }

        return in_array(strtoupper($region), array_map('strtoupper', $profile->excluded_regions));
    }

    /**
     * Check if the agent prefers the given lead source.
     */
    public function prefersSource(int $agentId, string $source): ?bool
    {
        $profile = AgentProfile::where('user_id', $agentId)->first();
        if (! $profile || empty($profile->preferred_lead_sources)) {
            return null; // Neutral — no preference set
        }

        return in_array(strtolower($source), array_map('strtolower', $profile->preferred_lead_sources));
    }

    /**
     * Full eligibility check: shift + availability flags.
     */
    public function isEligible(int $agentId): bool
    {
        $profile = AgentProfile::where('user_id', $agentId)->first();
        if (! $profile) {
            return false;
        }

        if (! $profile->is_available || ! $profile->auto_assign_enabled) {
            return false;
        }

        return $this->isWithinShift($agentId);
    }
}
