<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Waybill\Models\GoogleConnection;
use App\Domain\Waybill\Models\GoogleSheetConfig;
use App\Domain\Waybill\Services\GoogleSheetSyncService;
use App\Jobs\SyncGoogleSheetJob;
use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class GoogleSheetSyncController extends Controller
{
    private const MONTHS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    private const COURIERS = ['jnt', 'flash', 'spx'];

    public function index(Request $request)
    {
        $connection = GoogleConnection::active();
        $configs = GoogleSheetConfig::orderBy('data_year', 'desc')
            ->orderBy('courier')
            ->orderByRaw("ARRAY_POSITION(ARRAY['January','February','March','April','May','June','July','August','September','October','November','December'], month)")
            ->get();

        $currentYear = now()->year;

        // Recent sync runs (uploads with import_type='google_sync')
        $recentSyncs = Upload::where('type', 'waybill')
            ->where('import_type', 'google_sync')
            ->with('uploadedBy')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return Inertia::render('Waybills/GoogleSync', [
            'connection' => $connection ? [
                'email' => $connection->email,
                'connected_at' => $connection->connected_at,
                'expires_at' => $connection->expires_at,
                'is_expired' => $connection->isExpired(),
            ] : null,
            'configs' => $configs->map(fn ($c) => [
                'id' => $c->id,
                'courier' => $c->courier,
                'month' => $c->month,
                'data_year' => $c->data_year,
                'sheet_url' => $c->sheet_url,
                'sheet_tab_name' => $c->sheet_tab_name,
                'enabled' => $c->enabled,
            ]),
            'recent_syncs' => $recentSyncs,
            'months' => self::MONTHS,
            'couriers' => self::COURIERS,
            'current_year' => $currentYear,
            'redirect_uri' => url('/waybills/sync/callback'),
            'google_configured' => ! empty(config('services.google.client_id')),
        ]);
    }

    public function connect(Request $request)
    {
        if (empty(config('services.google.client_id'))) {
            return back()->with('error', 'Google OAuth not configured. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env');
        }

        $state = Str::uuid()->toString();
        session(['google_oauth_state' => $state]);

        $redirectUri = url('/waybills/sync/callback');
        $service = app(GoogleSheetSyncService::class);
        $authUrl = $service->getAuthUrl($redirectUri, $state);

        return redirect()->away($authUrl);
    }

    public function callback(Request $request)
    {
        if ($request->state !== session('google_oauth_state')) {
            return redirect('/waybills/sync')->with('error', 'OAuth state mismatch. Please try again.');
        }

        if ($request->has('error')) {
            return redirect('/waybills/sync')->with('error', 'Google authorization failed: '.$request->error);
        }

        $code = $request->code;
        if (! $code) {
            return redirect('/waybills/sync')->with('error', 'Missing authorization code from Google.');
        }

        try {
            $redirectUri = url('/waybills/sync/callback');
            $service = app(GoogleSheetSyncService::class);
            $tokens = $service->exchangeCode($code, $redirectUri);

            if (empty($tokens['refresh_token'])) {
                return redirect('/waybills/sync')->with('error', 'Google did not return a refresh token. Please revoke access in Google Account settings and try again.');
            }

            // Get user info
            $userInfo = $service->getUserInfo($tokens['access_token']);

            // Deactivate previous connections
            GoogleConnection::where('is_active', true)->update(['is_active' => false]);

            GoogleConnection::create([
                'google_user_id' => $userInfo['id'] ?? null,
                'email' => $userInfo['email'] ?? null,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600),
                'connected_by' => $request->user()->id,
                'connected_at' => now(),
                'is_active' => true,
            ]);

            return redirect('/waybills/sync')->with('success', 'Google account connected successfully.');

        } catch (\Throwable $e) {
            Log::error('Google OAuth callback failed: '.$e->getMessage());

            return redirect('/waybills/sync')->with('error', 'Failed to connect Google account: '.$e->getMessage());
        }
    }

    public function disconnect(Request $request)
    {
        $connection = GoogleConnection::active();
        if (! $connection) {
            return back()->with('error', 'No active Google connection to disconnect.');
        }

        $service = app(GoogleSheetSyncService::class);
        $service->revokeToken($connection);

        $connection->update(['is_active' => false]);

        return back()->with('success', 'Google account disconnected.');
    }

    public function status()
    {
        $connection = GoogleConnection::active();

        return response()->json([
            'connected' => (bool) $connection,
            'email' => $connection?->email,
            'expires_at' => $connection?->expires_at,
            'is_expired' => $connection?->isExpired(),
        ]);
    }

    public function saveConfigs(Request $request)
    {
        $validated = $request->validate([
            'configs' => 'required|array',
            'configs.*.courier' => 'required|string|in:jnt,flash,spx',
            'configs.*.month' => 'required|string',
            'configs.*.data_year' => 'required|integer',
            'configs.*.sheet_url' => 'nullable|string|max:2000',
            'configs.*.sheet_tab_name' => 'nullable|string|max:200',
            'configs.*.enabled' => 'boolean',
        ]);

        $userId = $request->user()->id;

        foreach ($validated['configs'] as $cfg) {
            GoogleSheetConfig::updateOrCreate(
                [
                    'courier' => $cfg['courier'],
                    'month' => $cfg['month'],
                    'data_year' => $cfg['data_year'],
                ],
                [
                    'sheet_url' => $cfg['sheet_url'] ?? null,
                    'sheet_tab_name' => $cfg['sheet_tab_name'] ?? null,
                    'enabled' => $cfg['enabled'] ?? true,
                    'updated_by' => $userId,
                ]
            );
        }

        return back()->with('success', 'Sheet configurations saved.');
    }

    public function run(Request $request)
    {
        $validated = $request->validate([
            'months' => 'required|array|min:1',
            'months.*' => 'required|string',
            'data_year' => 'required|integer',
            'couriers' => 'nullable|array',
            'couriers.*' => 'string|in:jnt,flash,spx',
        ]);

        $connection = GoogleConnection::active();
        if (! $connection) {
            return response()->json(['error' => 'No active Google connection. Please connect first.'], 422);
        }

        $couriers = $validated['couriers'] ?? self::COURIERS;
        $months = $validated['months'];
        $year = $validated['data_year'];
        $userId = $request->user()->id;

        $configs = GoogleSheetConfig::where('data_year', $year)
            ->where('enabled', true)
            ->whereIn('courier', $couriers)
            ->whereIn('month', $months)
            ->whereNotNull('sheet_url')
            ->get();

        if ($configs->isEmpty()) {
            return response()->json(['error' => 'No enabled sheet configs found for the selected months and couriers.'], 422);
        }

        $uploads = [];
        foreach ($configs as $config) {
            $upload = Upload::create([
                'filename' => 'gsheet_'.$config->courier.'_'.$config->month.'_'.$year,
                'original_filename' => 'Google Sheet: '.strtoupper($config->courier).' '.$config->month.' '.$year,
                'type' => 'waybill',
                'courier' => $config->courier,
                'import_type' => 'google_sync',
                'status' => Upload::STATUS_QUEUED,
                'uploaded_by' => $userId,
            ]);

            SyncGoogleSheetJob::dispatch($upload->id, $config->id, $userId);

            $uploads[] = [
                'upload_id' => $upload->id,
                'courier' => $config->courier,
                'month' => $config->month,
                'year' => $year,
            ];
        }

        return response()->json([
            'message' => 'Sync started for '.count($uploads).' source(s).',
            'uploads' => $uploads,
        ]);
    }

    public function runStatus(Upload $upload)
    {
        return response()->json([
            'id' => $upload->id,
            'status' => $upload->status,
            'courier' => $upload->courier,
            'import_type' => $upload->import_type,
            'total_rows' => $upload->total_rows,
            'processed_rows' => $upload->processed_rows,
            'success_rows' => $upload->success_rows,
            'inserted_rows' => $upload->inserted_rows,
            'updated_rows' => $upload->updated_rows,
            'skipped_rows' => $upload->skipped_rows,
            'error_rows' => $upload->error_rows,
            'original_filename' => $upload->original_filename,
            'created_at' => $upload->created_at,
            'completed_at' => $upload->completed_at,
        ]);
    }
}
