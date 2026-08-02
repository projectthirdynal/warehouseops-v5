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
            'fairness' => $this->analytics->fairnessMetrics($from, $to),
            'imbalanceAlerts' => $this->analytics->imbalanceAlerts($from, $to),
            'fairnessTrend' => $this->analytics->fairnessTrend($days),
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

    public function fairness(Request $request)
    {
        $days = (int) $request->input('days', 7);
        $from = now()->subDays($days);
        $to = now();

        return response()->json($this->analytics->fairnessMetrics($from, $to));
    }

    public function imbalanceAlerts(Request $request)
    {
        $days = (int) $request->input('days', 7);
        $from = now()->subDays($days);
        $to = now();

        return response()->json($this->analytics->imbalanceAlerts($from, $to));
    }

    public function fairnessTrend(Request $request)
    {
        $days = (int) $request->input('days', 14);

        return response()->json($this->analytics->fairnessTrend($days));
    }

    public function applyRebalancing(Request $request)
    {
        $validated = $request->validate([
            'threshold' => 'nullable|numeric|min:0.05|max:1.0',
        ]);

        $threshold = (float) ($validated['threshold'] ?? 0.15);
        $result = $this->analytics->applyRebalancing($threshold);

        return response()->json($result);
    }
}
