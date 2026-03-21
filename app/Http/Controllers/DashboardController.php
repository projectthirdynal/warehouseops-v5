<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // TODO: Replace with actual data from repositories
        $stats = [
            'total_waybills' => 12453,
            'pending_dispatch' => 47,
            'in_transit' => 234,
            'delivered_today' => 89,
            'returned_today' => 12,
            'total_leads' => 5678,
            'new_leads' => 156,
            'sales_today' => 34,
            'conversion_rate' => 18.5,
            'qc_pending' => 23,
            'agents_online' => 8,
        ];

        $recentActivity = [
            [
                'id' => 1,
                'type' => 'Waybill',
                'message' => 'Waybill #JNT123456 delivered successfully',
                'time' => '2 minutes ago',
            ],
            [
                'id' => 2,
                'type' => 'Lead',
                'message' => 'New lead assigned to Agent John',
                'time' => '5 minutes ago',
            ],
            [
                'id' => 3,
                'type' => 'QC',
                'message' => 'Sale #4521 approved by QC',
                'time' => '10 minutes ago',
            ],
            [
                'id' => 4,
                'type' => 'System',
                'message' => 'Batch upload completed: 150 waybills',
                'time' => '15 minutes ago',
            ],
        ];

        return Inertia::render('Dashboard/Index', [
            'stats' => $stats,
            'recentActivity' => $recentActivity,
        ]);
    }
}
