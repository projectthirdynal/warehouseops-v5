<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Imports\JntWaybillFastImport;
use App\Jobs\GenerateLeadsFromUpload;
use App\Models\Lead;
use App\Models\Upload;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Rap2hpoutre\FastExcel\FastExcel;

class DesktopApiController extends Controller
{
    /**
     * Health check / ping endpoint (no auth required)
     */
    public function ping(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'app' => 'WarehouseOps']);
    }

    /**
     * Login and return Sanctum token
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is deactivated'], 403);
        }

        // Only allow admin roles
        if (!in_array($user->role, ['superadmin', 'admin', 'supervisor'])) {
            return response()->json(['message' => 'Desktop app is restricted to admin users'], 403);
        }

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Create token
        $token = $user->createToken('desktop-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'theme' => $user->theme ?? 'light',
            ],
        ]);
    }

    /**
     * Logout (revoke current token)
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Get authenticated user info
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'theme' => $user->theme ?? 'light',
        ]);
    }

    /**
     * Dashboard data
     */
    public function dashboard(): JsonResponse
    {
        $stats = [
            'pending_dispatch' => Waybill::where('status', 'PENDING')->count(),
            'in_transit' => Waybill::whereIn('status', ['DISPATCHED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY'])->count(),
            'delivered_today' => Waybill::where('status', 'DELIVERED')->whereDate('delivered_at', today())->count(),
            'returned_today' => Waybill::where('status', 'RETURNED')->whereDate('returned_at', today())->count(),
            'new_leads' => Lead::where('status', 'NEW')->whereNull('assigned_to')->count(),
            'sales_today' => Lead::where('status', 'SALE')->whereDate('updated_at', today())->count(),
            'qc_pending' => Lead::where('sales_status', 'QA_PENDING')->count(),
            'agents_online' => User::where('role', 'agent')
                ->where('is_active', true)
                ->whereNotNull('last_login_at')
                ->where('last_login_at', '>=', now()->subHour())
                ->count(),
        ];

        // Hourly activity for today
        $hourlyActivity = [];
        for ($h = 8; $h <= 19; $h++) {
            $hourlyActivity[] = [
                'hour' => (string) $h,
                'waybills' => Waybill::whereDate('created_at', today())
                    ->whereRaw('EXTRACT(HOUR FROM created_at) = ?', [$h])
                    ->count(),
                'leads' => Lead::whereDate('created_at', today())
                    ->whereRaw('EXTRACT(HOUR FROM created_at) = ?', [$h])
                    ->count(),
            ];
        }

        // Recent activity
        $recentDeliveries = Waybill::where('status', 'DELIVERED')
            ->orderBy('delivered_at', 'desc')
            ->limit(3)
            ->get()
            ->map(fn($w) => [
                'id' => $w->id,
                'type' => 'Waybill',
                'description' => "Waybill #{$w->waybill_number} delivered",
                'time' => $w->delivered_at?->diffForHumans() ?? 'recently',
            ]);

        $recentLeads = Lead::whereNotNull('assigned_to')
            ->orderBy('updated_at', 'desc')
            ->limit(3)
            ->get()
            ->map(fn($l) => [
                'id' => $l->id + 10000,
                'type' => 'Lead',
                'description' => 'Lead assigned to agent',
                'time' => $l->updated_at->diffForHumans(),
            ]);

        $recentActivity = $recentDeliveries->merge($recentLeads)
            ->sortByDesc('time')
            ->take(6)
            ->values()
            ->toArray();

        return response()->json([
            'stats' => $stats,
            'hourly_activity' => $hourlyActivity,
            'recent_activity' => $recentActivity,
        ]);
    }

    /**
     * Validate a waybill number for scanner
     */
    public function scannerValidate(Request $request): JsonResponse
    {
        $request->validate(['waybill_number' => 'required|string']);

        $waybill = Waybill::where('waybill_number', $request->waybill_number)->first();

        if (!$waybill) {
            return response()->json([
                'valid' => false,
                'message' => 'Waybill not found in system',
            ]);
        }

        if ($waybill->status === 'DISPATCHED') {
            return response()->json([
                'valid' => false,
                'message' => 'Already dispatched',
            ]);
        }

        return response()->json([
            'valid' => true,
            'receiver_name' => $waybill->receiver_name,
            'status' => $waybill->status,
            'message' => 'Ready for dispatch',
        ]);
    }

    /**
     * Dispatch a batch of waybills
     */
    public function scannerDispatch(Request $request): JsonResponse
    {
        $request->validate(['waybill_numbers' => 'required|array|min:1']);

        $dispatched = 0;
        $errors = [];

        foreach ($request->waybill_numbers as $number) {
            $waybill = Waybill::where('waybill_number', $number)->first();

            if (!$waybill) {
                $errors[] = ['waybill' => $number, 'error' => 'Not found'];
                continue;
            }

            if ($waybill->status !== 'PENDING') {
                $errors[] = ['waybill' => $number, 'error' => "Cannot dispatch - status is {$waybill->status}"];
                continue;
            }

            $waybill->update([
                'status' => 'DISPATCHED',
                'dispatched_at' => now(),
            ]);
            $dispatched++;
        }

        return response()->json([
            'dispatched' => $dispatched,
            'errors' => $errors,
            'message' => "{$dispatched} waybills dispatched successfully",
        ]);
    }

    /**
     * Get import uploads list
     */
    public function imports(): JsonResponse
    {
        $uploads = Upload::where('type', 'waybill')
            ->with('uploadedBy')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $stats = [
            'total_uploads' => Upload::where('type', 'waybill')->count(),
            'total_imported' => Waybill::whereNotNull('upload_id')->count(),
            'pending_uploads' => Upload::where('type', 'waybill')
                ->whereIn('status', ['pending', 'processing'])
                ->count(),
            'recent_errors' => Upload::where('type', 'waybill')
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
        ];

        return response()->json([
            'uploads' => $uploads->map(fn($u) => [
                'id' => $u->id,
                'filename' => $u->filename,
                'original_filename' => $u->original_filename,
                'total_rows' => $u->total_rows ?? 0,
                'processed_rows' => $u->processed_rows ?? 0,
                'success_rows' => $u->success_rows ?? 0,
                'error_rows' => $u->error_rows ?? 0,
                'status' => $u->status,
                'errors' => $u->errors,
                'uploaded_by' => $u->uploadedBy ? ['name' => $u->uploadedBy->name] : null,
                'created_at' => $u->created_at->toISOString(),
            ]),
            'stats' => $stats,
        ]);
    }

    /**
     * Upload waybills file
     */
    public function importStore(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:102400',
            'courier_provider' => 'required|string|in:jnt,flash',
        ]);

        $file = $request->file('file');
        $courier = $request->input('courier_provider');

        $filename = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/waybills', $filename, 'local');

        $upload = Upload::create([
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'type' => 'waybill',
            'status' => 'processing',
            'uploaded_by' => $request->user()->id,
        ]);

        try {
            $rowCount = 0;
            (new FastExcel)->import(storage_path('app/' . $path), function ($row) use (&$rowCount) {
                $rowCount++;
            });
            $upload->update(['total_rows' => $rowCount]);

            if ($courier === 'jnt') {
                $import = new JntWaybillFastImport($upload, $request->user()->id);
                $import->import(storage_path('app/' . $path));

                $upload->update([
                    'status' => 'completed',
                    'errors' => $import->getErrors(),
                ]);

                GenerateLeadsFromUpload::dispatch($upload->id);

                return response()->json([
                    'message' => sprintf('%d waybills imported, %d errors', $import->getSuccessCount(), $import->getErrorCount()),
                    'upload_id' => $upload->id,
                ]);
            }

            return response()->json(['message' => 'Import completed', 'upload_id' => $upload->id]);

        } catch (\Exception $e) {
            $upload->markAsFailed(['message' => $e->getMessage()]);

            return response()->json(['message' => 'Import failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get upload detail
     */
    public function importShow(Upload $upload): JsonResponse
    {
        return response()->json([
            'id' => $upload->id,
            'filename' => $upload->filename,
            'original_filename' => $upload->original_filename,
            'total_rows' => $upload->total_rows ?? 0,
            'success_rows' => $upload->success_rows ?? 0,
            'error_rows' => $upload->error_rows ?? 0,
            'status' => $upload->status,
            'errors' => $upload->errors,
            'created_at' => $upload->created_at->toISOString(),
        ]);
    }

    /**
     * Retry failed upload
     */
    public function importRetry(Upload $upload): JsonResponse
    {
        if ($upload->status !== 'failed') {
            return response()->json(['message' => 'Only failed uploads can be retried'], 422);
        }

        $path = 'uploads/waybills/' . $upload->filename;

        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Original file not found'], 404);
        }

        $upload->update([
            'status' => 'processing',
            'processed_rows' => 0,
            'success_rows' => 0,
            'error_rows' => 0,
            'errors' => null,
        ]);

        try {
            $import = new JntWaybillFastImport($upload, $upload->uploaded_by);
            $import->import(storage_path('app/' . $path));

            $upload->update([
                'status' => 'completed',
                'errors' => $import->getErrors(),
            ]);

            GenerateLeadsFromUpload::dispatch($upload->id);

            return response()->json([
                'message' => sprintf('Retry completed: %d imported, %d errors', $import->getSuccessCount(), $import->getErrorCount()),
            ]);

        } catch (\Exception $e) {
            $upload->markAsFailed(['message' => $e->getMessage()]);

            return response()->json(['message' => 'Retry failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Monitoring metrics
     */
    public function monitoring(Request $request): JsonResponse
    {
        $dateRange = $request->query('date_range', 'today');

        // Lead metrics
        $totalLeads = Lead::count();
        $newToday = Lead::whereDate('created_at', today())->count();
        $converted = Lead::where('status', 'SALE')->count();
        $conversionRate = $totalLeads > 0 ? round(($converted / $totalLeads) * 100, 1) : 0;

        // Calculate trend vs yesterday
        $yesterdayLeads = Lead::whereDate('created_at', today()->subDay())->count();
        $leadTrend = $yesterdayLeads > 0
            ? round((($newToday - $yesterdayLeads) / $yesterdayLeads) * 100, 1)
            : 0;

        // Waybill metrics
        $deliveredToday = Waybill::where('status', 'DELIVERED')->whereDate('delivered_at', today())->count();
        $totalDispatched = Waybill::where('status', 'DISPATCHED')->whereDate('dispatched_at', today())->count();
        $deliveryRate = ($totalDispatched + $deliveredToday) > 0
            ? round(($deliveredToday / ($totalDispatched + $deliveredToday)) * 100, 1)
            : 0;

        // Agent metrics
        $totalAgents = User::where('role', 'agent')->count();
        $onlineAgents = User::where('role', 'agent')
            ->where('is_active', true)
            ->where('last_login_at', '>=', now()->subHour())
            ->count();

        // Revenue (COD amounts from delivered waybills)
        $revenueToday = Waybill::where('status', 'DELIVERED')
            ->whereDate('delivered_at', today())
            ->sum('cod_amount');
        $revenueWeek = Waybill::where('status', 'DELIVERED')
            ->where('delivered_at', '>=', now()->startOfWeek())
            ->sum('cod_amount');
        $revenueMonth = Waybill::where('status', 'DELIVERED')
            ->where('delivered_at', '>=', now()->startOfMonth())
            ->sum('cod_amount');

        $yesterdayRevenue = Waybill::where('status', 'DELIVERED')
            ->whereDate('delivered_at', today()->subDay())
            ->sum('cod_amount');
        $revenueTrend = $yesterdayRevenue > 0
            ? round((($revenueToday - $yesterdayRevenue) / $yesterdayRevenue) * 100, 1)
            : 0;

        // Hourly activity
        $hourlyActivity = [];
        for ($h = 8; $h <= 17; $h++) {
            $suffix = $h >= 12 ? 'PM' : 'AM';
            $display = $h > 12 ? $h - 12 : $h;
            $hourlyActivity[] = [
                'hour' => "{$display}{$suffix}",
                'leads' => Lead::whereDate('created_at', today())
                    ->whereRaw('EXTRACT(HOUR FROM created_at) = ?', [$h])
                    ->count(),
                'sales' => Lead::where('status', 'SALE')
                    ->whereDate('updated_at', today())
                    ->whereRaw('EXTRACT(HOUR FROM updated_at) = ?', [$h])
                    ->count(),
            ];
        }

        // Top agents
        $topAgents = User::where('role', 'agent')
            ->where('is_active', true)
            ->get()
            ->map(function ($agent) {
                $leads = Lead::where('assigned_to', $agent->id)->count();
                $sales = Lead::where('assigned_to', $agent->id)->where('status', 'SALE')->count();
                return [
                    'name' => $agent->name,
                    'leads' => $leads,
                    'sales' => $sales,
                    'conversion_rate' => $leads > 0 ? round(($sales / $leads) * 100, 1) : 0,
                ];
            })
            ->sortByDesc('conversion_rate')
            ->take(5)
            ->values()
            ->toArray();

        $topPerformer = !empty($topAgents) ? $topAgents[0]['name'] : '-';
        $avgPerformance = count($topAgents) > 0
            ? round(collect($topAgents)->avg('conversion_rate'), 1)
            : 0;

        return response()->json([
            'leads' => [
                'total' => $totalLeads,
                'new_today' => $newToday,
                'converted' => $converted,
                'conversion_rate' => $conversionRate,
                'trend' => $leadTrend,
            ],
            'waybills' => [
                'total' => Waybill::where('status', 'PENDING')->count(),
                'dispatched_today' => $totalDispatched,
                'delivered_today' => $deliveredToday,
                'returned_today' => Waybill::where('status', 'RETURNED')->whereDate('returned_at', today())->count(),
                'delivery_rate' => $deliveryRate,
            ],
            'agents' => [
                'total' => $totalAgents,
                'online' => $onlineAgents,
                'avg_performance' => $avgPerformance,
                'top_performer' => $topPerformer,
            ],
            'revenue' => [
                'today' => $revenueToday,
                'this_week' => $revenueWeek,
                'this_month' => $revenueMonth,
                'trend' => $revenueTrend,
            ],
            'hourly_activity' => $hourlyActivity,
            'top_agents' => $topAgents,
        ]);
    }
}
