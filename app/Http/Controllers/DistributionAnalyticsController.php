<?php

namespace App\Http\Controllers;

use App\Services\DistributionAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DistributionAnalyticsController extends Controller
{
    public function __construct(
        private DistributionAnalyticsService $analytics,
    ) {}

    public function index(Request $request)
    {
        $days = (int) $request->input('days', 7);
        $from = now()->subDays($days);
        $to = now();

        return Inertia::render('Distribution/Analytics', [
            'timeToAssign' => $this->analytics->averageTimeToAssign($from, $to),
            'timeDistribution' => $this->analytics->timeToAssignDistribution($from, $to),
            'utilization' => $this->analytics->agentUtilization(),
            'queueDepth' => $this->analytics->queueDepthOverTime(),
            'queueSnapshot' => $this->analytics->queueSnapshot(),
            'strategyPerformance' => $this->analytics->strategyPerformance($from, $to),
            'alerts' => $this->analytics->supervisorAlerts(),
            'rebalancing' => $this->analytics->rebalancingReport(),
            'days' => $days,
        ]);
    }

    public function alerts()
    {
        return response()->json($this->analytics->supervisorAlerts());
    }

    public function rebalancing()
    {
        return response()->json([
            'report' => $this->analytics->rebalancingReport(),
        ]);
    }
}
