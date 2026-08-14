<?php

namespace App\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentBurnoutPrediction;
use App\Models\AgentWorkload;
use App\Models\LeadCycle;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class BurnoutPredictionService
{
    private const VERSION = 'v1';

    /**
     * Recalculate burnout predictions for all active agents.
     *
     * @return array{processed: int, high_risk: int, critical: int}
     */
    public function recalculateAll(): array
    {
        $agents = User::where('role', 'agent')
            ->where('is_active', true)
            ->with('agentProfile')
            ->get();

        AgentBurnoutPrediction::query()->delete();

        $highRisk = 0;
        $critical = 0;

        foreach ($agents as $agent) {
            $prediction = $this->predictForAgent($agent);
            AgentBurnoutPrediction::create([
                'agent_id' => $agent->id,
                'risk_score' => $prediction['risk_score'],
                'risk_level' => $prediction['risk_level'],
                'features' => $prediction['features'],
                'recommendation' => $prediction['recommendation'],
                'model_version' => self::VERSION,
                'calculated_at' => now(),
            ]);

            if ($prediction['risk_level'] === 'high') {
                $highRisk++;
            } elseif ($prediction['risk_level'] === 'critical') {
                $critical++;
            }
        }

        return [
            'processed' => $agents->count(),
            'high_risk' => $highRisk,
            'critical' => $critical,
        ];
    }

    /**
     * Predict burnout risk for a single agent.
     *
     * @return array{risk_score: int, risk_level: string, features: array<string, mixed>, recommendation: string}
     */
    public function predictForAgent(User $agent): array
    {
        $features = $this->extractFeatures($agent);
        $riskScore = $this->computeRiskScore($features);
        $riskLevel = $this->riskLevel($riskScore);
        $recommendation = $this->buildRecommendation($features, $riskScore, $riskLevel);

        return [
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'features' => $features,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Get team-level burnout summary.
     */
    public function getTeamSummary(): array
    {
        $latest = AgentBurnoutPrediction::with('agent:id,name,role,is_active')
            ->whereIn(
                'id',
                AgentBurnoutPrediction::selectRaw('MAX(id)')
                    ->groupBy('agent_id')
            )
            ->get();

        $counts = $latest->countBy(fn ($p) => $p->risk_level);

        return [
            'total_agents' => $latest->count(),
            'critical' => $counts->get('critical', 0),
            'high' => $counts->get('high', 0),
            'medium' => $counts->get('medium', 0),
            'low' => $counts->get('low', 0),
            'avg_risk_score' => $latest->isEmpty() ? 0 : round($latest->avg('risk_score'), 1),
            'at_risk_count' => $latest->whereIn('risk_level', ['high', 'critical'])->count(),
            'last_calculated_at' => $latest->max('calculated_at')?->toIso8601String(),
        ];
    }

    /**
     * Get paginated agent burnout list.
     *
     * @return array{agents: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function getAgentList(?string $riskLevel = null, ?string $search = null): array
    {
        $query = AgentBurnoutPrediction::with('agent:id,name,role,is_active')
            ->whereIn(
                'id',
                AgentBurnoutPrediction::selectRaw('MAX(id)')
                    ->groupBy('agent_id')
            )
            ->orderByDesc('risk_score');

        if ($riskLevel) {
            $query->where('risk_level', $riskLevel);
        }

        if ($search) {
            $query->whereHas('agent', fn ($q) => $q->where('name', 'ILIKE', "%{$search}%"));
        }

        $predictions = $query->get();

        return [
            'agents' => $predictions->map(fn ($p) => [
                'agent_id' => $p->agent_id,
                'name' => $p->agent?->name ?? 'Unknown',
                'risk_score' => $p->risk_score,
                'risk_level' => $p->risk_level,
                'features' => $p->features,
                'recommendation' => $p->recommendation,
                'calculated_at' => $p->calculated_at?->toIso8601String(),
            ])->values()->all(),
            'summary' => $this->getTeamSummary(),
        ];
    }

    /**
     * Extract activity-pattern features for an agent.
     *
     * @return array<string, mixed>
     */
    private function extractFeatures(User $agent): array
    {
        $profile = $agent->agentProfile;
        $workload = AgentWorkload::where('agent_id', $agent->id)->first();

        $now = now();
        $today = today();
        $sevenDaysAgo = $today->copy()->subDays(6);
        $fourteenDaysAgo = $today->copy()->subDays(13);
        $thirtyDaysAgo = $today->copy()->subDays(29);

        // Workload
        $activeLeads = Lead::where('assigned_to', $agent->id)
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->count();
        $maxConcurrent = $profile?->concurrent_lead_cap ?? $profile?->max_active_cycles ?? 10;
        $workloadUtilization = $maxConcurrent > 0
            ? min(1, $activeLeads / $maxConcurrent)
            : 0;
        $todayAssigned = $this->freshTodayAssigned($workload);
        $todayConverted = $workload?->today_converted_count ?? 0;

        // Calls & cycles
        $cycles7d = $this->cyclesBetween($agent->id, $sevenDaysAgo, $now);
        $cycles30d = $this->cyclesBetween($agent->id, $thirtyDaysAgo, $now);

        $calls7d = (int) $cycles7d->sum('call_count');
        $calls30d = (int) $cycles30d->sum('call_count');
        $avgDailyCalls7d = round($calls7d / 7, 2);
        $avgDailyCalls30d = round($calls30d / 30, 2);

        $closed7d = $cycles7d->whereNotNull('closed_at');
        $closed30d = $cycles30d->whereNotNull('closed_at');

        $sales7d = (int) $closed7d->where('outcome', 'ORDERED')->count();
        $sales30d = (int) $closed30d->where('outcome', 'ORDERED')->count();

        $conversion7d = $closed7d->count() > 0
            ? round($sales7d / $closed7d->count(), 4)
            : 0;
        $conversion30d = $closed30d->count() > 0
            ? round($sales30d / $closed30d->count(), 4)
            : 0;

        // Trend: negative when short-term conversion falls below longer-term conversion
        $conversionTrend = $conversion30d > 0
            ? round(($conversion7d - $conversion30d) / $conversion30d, 4)
            : 0;

        // After-hours / weekend activity
        $lastCallTimes = $cycles7d->pluck('last_call_at')->filter();
        $lateNightHours = $this->countAfterHours($lastCallTimes, 22, 6);
        $weekendHours = $this->countWeekendHours($lastCallTimes);

        // Shift adherence
        $shiftStart = $profile?->shift_start;
        $shiftEnd = $profile?->shift_end;
        $callsOutsideShift = $this->countCallsOutsideShift($lastCallTimes, $shiftStart, $shiftEnd);

        // Consecutive work days and rest days
        $activeDays14d = $this->activeDays($agent->id, $fourteenDaysAgo, $now);
        $consecutiveWorkDays = $this->maxConsecutiveDays($activeDays14d);
        $restDays14d = 14 - count($activeDays14d);

        // Handle time
        $avgHandleTimeHours = $closed30d->isNotEmpty()
            ? round($closed30d->avg(fn (LeadCycle $c) => $c->opened_at && $c->closed_at
                ? $c->opened_at->diffInMinutes($c->closed_at) / 60
                : 0), 2)
            : 0;

        // Idle / availability gap
        $lastSeenMinutes = $profile?->last_seen_at
            ? (int) $profile->last_seen_at->diffInMinutes($now)
            : null;
        $idleThreshold = $profile?->idle_threshold_minutes ?? 15;
        $isCurrentlyIdle = $lastSeenMinutes !== null && $lastSeenMinutes > $idleThreshold;

        return [
            'active_leads' => $activeLeads,
            'max_concurrent' => $maxConcurrent,
            'workload_utilization' => $workloadUtilization,
            'today_assigned' => $todayAssigned,
            'today_converted' => $todayConverted,
            'calls_7d' => $calls7d,
            'calls_30d' => $calls30d,
            'avg_daily_calls_7d' => $avgDailyCalls7d,
            'avg_daily_calls_30d' => $avgDailyCalls30d,
            'sales_7d' => $sales7d,
            'sales_30d' => $sales30d,
            'conversion_7d' => $conversion7d,
            'conversion_30d' => $conversion30d,
            'conversion_trend' => $conversionTrend,
            'late_night_hours_7d' => $lateNightHours,
            'weekend_hours_7d' => $weekendHours,
            'calls_outside_shift_7d' => $callsOutsideShift,
            'active_days_14d' => count($activeDays14d),
            'consecutive_work_days' => $consecutiveWorkDays,
            'rest_days_14d' => $restDays14d,
            'avg_handle_time_hours' => $avgHandleTimeHours,
            'last_seen_minutes' => $lastSeenMinutes,
            'is_currently_idle' => $isCurrentlyIdle,
            'performance_score' => (float) ($profile?->performance_score ?? 50),
        ];
    }

    /**
     * Compute a 0-100 burnout risk score from features.
     */
    private function computeRiskScore(array $features): int
    {
        $score = 0.0;

        // Workload pressure (max ~25 pts)
        $score += min(25, $features['workload_utilization'] * 30);
        if ($features['today_assigned'] >= 20) {
            $score += 8;
        } elseif ($features['today_assigned'] >= 12) {
            $score += 4;
        }

        // Sustained intensity (max ~25 pts)
        if ($features['avg_daily_calls_7d'] >= 40) {
            $score += 12;
        } elseif ($features['avg_daily_calls_7d'] >= 25) {
            $score += 6;
        }
        if ($features['active_days_14d'] >= 12) {
            $score += 8;
        } elseif ($features['active_days_14d'] >= 10) {
            $score += 4;
        }
        if ($features['consecutive_work_days'] >= 7) {
            $score += 10;
        } elseif ($features['consecutive_work_days'] >= 5) {
            $score += 5;
        }

        // Recovery deficit (max ~20 pts)
        if ($features['rest_days_14d'] <= 1) {
            $score += 12;
        } elseif ($features['rest_days_14d'] <= 3) {
            $score += 6;
        }
        if ($features['late_night_hours_7d'] >= 5) {
            $score += 8;
        } elseif ($features['late_night_hours_7d'] >= 2) {
            $score += 4;
        }
        if ($features['weekend_hours_7d'] >= 4) {
            $score += 6;
        } elseif ($features['weekend_hours_7d'] >= 2) {
            $score += 3;
        }
        if ($features['calls_outside_shift_7d'] >= 5) {
            $score += 4;
        }

        // Declining performance (max ~20 pts)
        if ($features['conversion_trend'] < -0.25) {
            $score += 12;
        } elseif ($features['conversion_trend'] < -0.10) {
            $score += 6;
        }
        if ($features['performance_score'] < 40) {
            $score += 8;
        } elseif ($features['performance_score'] < 55) {
            $score += 4;
        }

        // Availability / engagement (max ~10 pts)
        if ($features['is_currently_idle']) {
            $score += 3;
        }
        if ($features['avg_handle_time_hours'] >= 4) {
            $score += 4;
        } elseif ($features['avg_handle_time_hours'] >= 2.5) {
            $score += 2;
        }

        return (int) round(max(0, min(100, $score)));
    }

    private function riskLevel(int $score): string
    {
        return match (true) {
            $score >= 75 => 'critical',
            $score >= 55 => 'high',
            $score >= 35 => 'medium',
            default => 'low',
        };
    }

    private function buildRecommendation(array $features, int $score, string $level): string
    {
        if ($level === 'low') {
            return 'No immediate burnout risk. Continue monitoring workload patterns.';
        }

        $parts = [];

        if ($features['workload_utilization'] >= 0.8) {
            $parts[] = 'cap load near limit ('.round($features['workload_utilization'] * 100).'% utilized)';
        }
        if ($features['avg_daily_calls_7d'] >= 30) {
            $parts[] = 'very high call volume';
        }
        if ($features['rest_days_14d'] <= 2) {
            $parts[] = 'insufficient rest days';
        }
        if ($features['consecutive_work_days'] >= 6) {
            $parts[] = 'long consecutive work streak';
        }
        if ($features['conversion_trend'] < -0.15) {
            $parts[] = 'declining conversion trend';
        }
        if ($features['late_night_hours_7d'] >= 3 || $features['weekend_hours_7d'] >= 3) {
            $parts[] = 'after-hours activity detected';
        }

        if (empty($parts)) {
            return 'Moderate burnout risk — review schedule and workload balance.';
        }

        $action = $level === 'critical'
            ? 'Immediate intervention recommended: reduce assignments, enforce rest, and review shift schedule.'
            : 'Consider reducing assignments or adding rest days.';

        return 'Risk drivers: '.implode(', ', $parts).'. '.$action;
    }

    private function freshTodayAssigned(?AgentWorkload $workload): int
    {
        if (! $workload) {
            return 0;
        }

        if ($workload->last_assigned_at && ! $workload->last_assigned_at->isToday()) {
            return 0;
        }

        return $workload->today_assigned_count;
    }

    /**
     * @return Collection<int, LeadCycle>
     */
    private function cyclesBetween(int $agentId, Carbon $from, Carbon $to): Collection
    {
        return LeadCycle::where('assigned_agent_id', $agentId)
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('opened_at', [$from, $to])
                    ->orWhereBetween('closed_at', [$from, $to])
                    ->orWhereBetween('last_call_at', [$from, $to]);
            })
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ?Carbon>  $times
     */
    private function countAfterHours($times, int $lateStart, int $earlyEnd): int
    {
        return $times->filter(function ($time) use ($lateStart, $earlyEnd) {
            if (! $time instanceof Carbon) {
                return false;
            }
            $hour = (int) $time->format('H');

            return $hour >= $lateStart || $hour < $earlyEnd;
        })->count();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ?Carbon>  $times
     */
    private function countWeekendHours($times): int
    {
        return $times->filter(function ($time) {
            if (! $time instanceof Carbon) {
                return false;
            }
            $dayOfWeek = (int) $time->format('w');

            return $dayOfWeek === 0 || $dayOfWeek === 6;
        })->count();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ?Carbon>  $times
     */
    private function countCallsOutsideShift($times, ?string $shiftStart, ?string $shiftEnd): int
    {
        if (! $shiftStart || ! $shiftEnd) {
            return 0;
        }

        $start = Carbon::parse($shiftStart);
        $end = Carbon::parse($shiftEnd);

        return $times->filter(function ($time) use ($start, $end) {
            if (! $time instanceof Carbon) {
                return false;
            }
            $nowTime = $time->format('H:i');
            $startTime = $start->format('H:i');
            $endTime = $end->format('H:i');

            if ($endTime < $startTime) {
                return $nowTime < $startTime && $nowTime >= $endTime;
            }

            return $nowTime < $startTime || $nowTime >= $endTime;
        })->count();
    }

    /**
     * @return array<int, string>
     */
    private function activeDays(int $agentId, Carbon $from, Carbon $to): array
    {
        return LeadCycle::where('assigned_agent_id', $agentId)
            ->whereBetween('last_call_at', [$from, $to])
            ->selectRaw('DATE(last_call_at) as day')
            ->groupBy('day')
            ->pluck('day')
            ->map(fn ($d) => (string) $d)
            ->toArray();
    }

    /**
     * @param  array<int, string>  $activeDays
     */
    private function maxConsecutiveDays(array $activeDays): int
    {
        if (empty($activeDays)) {
            return 0;
        }

        $dates = collect($activeDays)
            ->map(fn ($d) => Carbon::parse($d))
            ->sort()
            ->values();

        $max = 1;
        $current = 1;
        for ($i = 1; $i < $dates->count(); $i++) {
            $prev = $dates[$i - 1];
            $curr = $dates[$i];
            if ($curr->diffInDays($prev) === 1) {
                $current++;
                $max = max($max, $current);
            } else {
                $current = 1;
            }
        }

        return $max;
    }
}
