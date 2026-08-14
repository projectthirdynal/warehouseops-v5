<?php

namespace App\Http\Controllers;

use App\Services\SourceAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SourceAnalyticsController extends Controller
{
    public function __construct(
        private SourceAnalyticsService $analytics,
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()->role, ['superadmin', 'admin', 'supervisor', 'finance'])) {
                abort(403, 'Supervisors and finance only');
            }

            return $next($request);
        });
    }

    public function index(Request $request): Response
    {
        $days = (int) $request->input('days', 30);
        $from = now()->subDays($days);
        $to = now();

        $data = $this->analytics->getAnalytics($from, $to);

        return Inertia::render('Distribution/SourceAnalytics', array_merge($data, ['days' => $days]));
    }

    public function api(Request $request)
    {
        $days = (int) $request->input('days', 30);
        $from = now()->subDays($days);
        $to = now();

        return response()->json($this->analytics->getAnalytics($from, $to));
    }
}
