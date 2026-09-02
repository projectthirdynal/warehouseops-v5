<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Modules\Finance\Jobs\QboSyncJob;
use Modules\Finance\Models\QboAccountMapping;
use Modules\Finance\Models\QboConnection;
use Modules\Finance\Models\QboSyncQueue;
use Modules\Finance\Services\QboClient;
use Modules\Finance\Services\QboSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;

class QuickBooksController extends Controller
{
    private const AUTH_URL = 'https://appcenter.intuit.com/connect/oauth2';

    private const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    private const SCOPE = 'com.intuit.quickbooks.accounting';

    private function qboCredentials(string $environment): array
    {
        $environment = strtolower($environment) === 'production' ? 'production' : 'sandbox';

        return [
            'client_id' => (string) config("services.qbo.{$environment}.client_id"),
            'client_secret' => (string) config("services.qbo.{$environment}.client_secret"),
        ];
    }

    public function dashboard(Request $request)
    {
        $connection = QboConnection::active();
        $stats = [
            'pending' => QboSyncQueue::pending()->count(),
            'failed' => QboSyncQueue::failed()->count(),
            'synced' => QboSyncQueue::synced()->count(),
        ];

        $query = QboSyncQueue::latest();
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }
        $recent = $query->limit(50)->get([
            'id', 'entity_type', 'entity_id', 'operation', 'status',
            'qbo_id', 'attempts', 'error_message', 'synced_at', 'created_at',
        ]);

        $mappings = QboAccountMapping::all()->keyBy('mapping_key');
        $required = ['inventory_asset', 'cogs', 'accounts_payable', 'bank_account', 'undeposited_funds', 'shipping_expense', 'commission_expense', 'revenue'];
        $mappingStatus = [];
        foreach ($required as $key) {
            $mappingStatus[$key] = $mappings->get($key)?->qbo_account_name;
        }

        $sandboxCredentials = $this->qboCredentials('sandbox');
        $productionCredentials = $this->qboCredentials('production');

        $syncService = app(QboSyncService::class);

        return Inertia::render('Finance/QuickBooks/Dashboard', [
            'connection' => $connection ? [
                'realm_id' => $connection->realm_id,
                'environment' => $connection->environment,
                'connected_at' => $connection->connected_at,
                'expires_at' => $connection->expires_at,
                'is_expired' => $connection->isExpired(),
            ] : null,
            'stats' => $stats,
            'recent' => $recent,
            'mapping_status' => $mappingStatus,
            'credentials_configured' => (
                $sandboxCredentials['client_id'] !== '' && $sandboxCredentials['client_secret'] !== ''
            ) || (
                $productionCredentials['client_id'] !== '' && $productionCredentials['client_secret'] !== ''
            ),
            'redirect_uri' => url('/finance/quickbooks/callback'),
            'entity_stats' => $syncService->getEntityStats(),
            'sync_settings' => $syncService->getSyncSettings(),
            'filters' => $request->only(['status', 'entity_type']),
        ]);
    }

    public function connect(Request $request)
    {
        $redirectUri = url('/finance/quickbooks/callback');
        $environment = strtolower((string) $request->query('env', 'sandbox')) === 'production'
            ? 'production'
            : 'sandbox';
        $credentials = $this->qboCredentials($environment);
        $clientId = $credentials['client_id'];

        if (! $clientId) {
            $envKey = $environment === 'production' ? 'QBO_PRODUCTION_CLIENT_ID' : 'QBO_SANDBOX_CLIENT_ID';

            return back()->with('error', "{$envKey} not configured. Add the {$environment} QuickBooks credentials to .env.");
        }

        $state = Str::uuid()->toString();
        session(['qbo_oauth_state' => $state, 'qbo_oauth_env' => $environment]);

        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return redirect()->away(self::AUTH_URL.'?'.$params);
    }

    public function callback(Request $request)
    {
        if ($request->state !== session('qbo_oauth_state')) {
            return redirect('/finance/quickbooks')->with('error', 'OAuth state mismatch.');
        }
        $code = $request->code;
        $realmId = $request->realmId;
        if (! $code || ! $realmId) {
            return redirect('/finance/quickbooks')->with('error', 'Missing code or realmId from QuickBooks.');
        }

        $environment = strtolower((string) (session('qbo_oauth_env') ?? 'sandbox')) === 'production'
            ? 'production'
            : 'sandbox';
        $credentials = $this->qboCredentials($environment);
        $clientId = $credentials['client_id'];
        $clientSecret = $credentials['client_secret'];
        $redirectUri = url('/finance/quickbooks/callback');

        $resp = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

        if (! $resp->successful()) {
            return redirect('/finance/quickbooks')->with('error', 'OAuth token exchange failed: '.$resp->body());
        }

        $data = $resp->json();
        QboConnection::query()->update(['is_active' => false]);
        QboConnection::create([
            'realm_id' => $realmId,
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            'environment' => strtoupper($environment),
            'connected_by' => $request->user()->id,
            'connected_at' => now(),
            'is_active' => true,
        ]);

        return redirect('/finance/quickbooks')->with('success', 'QuickBooks connected.');
    }

    public function disconnect()
    {
        QboConnection::query()->update(['is_active' => false]);

        return back()->with('success', 'QuickBooks disconnected.');
    }

    public function retry(QboSyncQueue $queue)
    {
        $queue->update(['status' => 'PENDING', 'error_message' => null]);
        QboSyncJob::dispatch($queue->id);

        return back()->with('success', 'Re-queued for sync.');
    }

    /**
     * Fetch account list from QBO so the user can map them.
     */
    public function accounts()
    {
        try {
            $client = new QboClient;
            $data = $client->query('SELECT Id, Name, AccountType, AccountSubType FROM Account WHERE Active = true ORDERBY Name MAXRESULTS 1000');

            return response()->json($data['QueryResponse']['Account'] ?? []);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function mappings()
    {
        $mappings = QboAccountMapping::all()->keyBy('mapping_key');
        $keys = [
            'inventory_asset' => 'Inventory Asset',
            'cogs' => 'Cost of Goods Sold',
            'accounts_payable' => 'Accounts Payable',
            'bank_account' => 'Bank Account (deposits)',
            'undeposited_funds' => 'Undeposited Funds',
            'shipping_expense' => 'Shipping / Courier Expense',
            'commission_expense' => 'Commission Expense',
            'revenue' => 'Sales Revenue',
        ];

        return Inertia::render('Finance/QuickBooks/Mappings', [
            'keys' => $keys,
            'mappings' => $mappings,
            'qbo_active' => QboConnection::active() !== null,
        ]);
    }

    public function saveMapping(Request $request)
    {
        $data = $request->validate([
            'mapping_key' => 'required|string|max:60',
            'qbo_account_id' => 'required|string|max:60',
            'qbo_account_name' => 'nullable|string|max:200',
        ]);

        QboAccountMapping::updateOrCreate(
            ['mapping_key' => $data['mapping_key']],
            [
                'qbo_account_id' => $data['qbo_account_id'],
                'qbo_account_name' => $data['qbo_account_name'] ?? null,
                'mapped_by' => $request->user()->id,
            ],
        );

        return back()->with('success', 'Mapping saved.');
    }

    public function bulkRetry()
    {
        $count = app(QboSyncService::class)->bulkRetry();

        return back()->with('success', "Re-queued {$count} failed sync item(s).");
    }

    public function updateSyncSettings(Request $request)
    {
        $validated = $request->validate([
            'auto_sync_invoice' => 'nullable|boolean',
            'auto_sync_payment' => 'nullable|boolean',
            'auto_sync_bill' => 'nullable|boolean',
            'auto_sync_bill_payment' => 'nullable|boolean',
            'auto_sync_deposit' => 'nullable|boolean',
            'auto_sync_cogs' => 'nullable|boolean',
        ]);

        app(QboSyncService::class)->updateSyncSettings($validated);

        return back()->with('success', 'Sync settings updated.');
    }
}
