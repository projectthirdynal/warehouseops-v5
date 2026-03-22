<?php

namespace App\Services;

use App\Models\FraudFlag;
use App\Models\LeadCycle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FraudDetectionService
{
    public function runAllDetections(): array
    {
        return [
            'suspicious_velocity' => $this->detectSuspiciousVelocity(),
            'no_call_outcomes' => $this->detectNoCallOutcomes(),
            'lead_hoarding' => $this->detectLeadHoarding(),
        ];
    }

    public function detectSuspiciousVelocity(int $threshold = 10, int $minutes = 30): Collection
    {
        $flags = collect();

        $suspicious = LeadCycle::select('assigned_agent_id', 'outcome', DB::raw('COUNT(*) as count'))
            ->where('closed_at', '>=', now()->subMinutes($minutes))
            ->whereNotNull('outcome')
            ->groupBy('assigned_agent_id', 'outcome')
            ->having('count', '>=', $threshold)
            ->get();

        foreach ($suspicious as $record) {
            $exists = FraudFlag::where('agent_id', $record->assigned_agent_id)
                ->where('flag_type', 'SUSPICIOUS_VELOCITY')
                ->whereDate('created_at', today())
                ->exists();

            if (!$exists) {
                $flag = FraudFlag::create([
                    'agent_id' => $record->assigned_agent_id,
                    'flag_type' => 'SUSPICIOUS_VELOCITY',
                    'severity' => 'WARNING',
                    'details' => [
                        'outcome' => $record->outcome,
                        'count' => $record->count,
                        'period_minutes' => $minutes,
                    ],
                ]);
                $flags->push($flag);
            }
        }

        return $flags;
    }

    public function detectNoCallOutcomes(): Collection
    {
        $flags = collect();

        $suspicious = LeadCycle::whereNotNull('outcome')
            ->where('call_count', 0)
            ->whereDate('closed_at', today())
            ->get();

        foreach ($suspicious as $cycle) {
            $exists = FraudFlag::where('agent_id', $cycle->assigned_agent_id)
                ->where('lead_id', $cycle->lead_id)
                ->where('flag_type', 'NO_CALL_INITIATED')
                ->exists();

            if (!$exists) {
                $flag = FraudFlag::create([
                    'agent_id' => $cycle->assigned_agent_id,
                    'lead_id' => $cycle->lead_id,
                    'flag_type' => 'NO_CALL_INITIATED',
                    'severity' => 'CRITICAL',
                    'details' => [
                        'cycle_id' => $cycle->id,
                        'outcome' => $cycle->outcome,
                    ],
                ]);
                $flags->push($flag);
            }
        }

        return $flags;
    }

    public function detectLeadHoarding(int $hoursThreshold = 24): Collection
    {
        $flags = collect();

        $suspicious = LeadCycle::where('status', 'ACTIVE')
            ->where('call_count', 0)
            ->where('opened_at', '<=', now()->subHours($hoursThreshold))
            ->get();

        foreach ($suspicious as $cycle) {
            $exists = FraudFlag::where('agent_id', $cycle->assigned_agent_id)
                ->where('lead_id', $cycle->lead_id)
                ->where('flag_type', 'LEAD_HOARDING')
                ->exists();

            if (!$exists) {
                $flag = FraudFlag::create([
                    'agent_id' => $cycle->assigned_agent_id,
                    'lead_id' => $cycle->lead_id,
                    'flag_type' => 'LEAD_HOARDING',
                    'severity' => 'WARNING',
                    'details' => [
                        'hours_since_assignment' => now()->diffInHours($cycle->opened_at),
                    ],
                ]);
                $flags->push($flag);
            }
        }

        return $flags;
    }

    public function getUnreviewedFlags(): Collection
    {
        return FraudFlag::unreviewed()
            ->with(['agent', 'lead'])
            ->orderByDesc('created_at')
            ->get();
    }
}
