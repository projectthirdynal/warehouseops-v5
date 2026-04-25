<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Finance\Jobs\QboSyncJob;
use App\Domain\Finance\Models\QboAccountMapping;
use App\Domain\Finance\Models\QboConnection;
use App\Domain\Finance\Models\QboSyncQueue;
use App\Domain\Finance\Services\QboClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;

class QuickBooksController extends Controller
{
    private const AUTH_URL  = 'https://appcenter.intuit.com/connect/oauth2';
    private const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    private const SCOPE     = 'com.intuit.quickbooks.accounting';

    public function dashboard()
    {
        $connection = QboConnection::active();
        $stats = [
            'pending' => QboSyncQueue::pending()->count(),
            'failed'  => QboSyncQueue::failed()->count(),
            'synced'  => QboSyncQueue::synced()->count(),
        ];

        $recent = QboSyncQueue::latest()->limit(50)->get([
            'id', 'entity_type', 'entity_id', 'operation', 'status',
            'qbo_id', 'attempts', 'error_message', 'synced_at', 'created_at',
        ]);

        $mappings = QboAccountMapping::all()->keyBy('mapping_key');
        $required = ['inventory_asset', 'cogs', 'accounts_payable', 'bank_account', 'undeposited_funds', 'shipping_expense', 'commission_expense', 'revenue'];
        $mappingStatus = [];
        foreach ($required as $key) {
            $mappingStatus[$key] = $mappings->get($key)?->qbo_account_name;
        }

        $clientId     = (string) config('services.qbo.client_id');
        $clientSecret = (string) config('services.qbo.client_secret');

        return Inertia::render('Finance/QuickBooks/Dashboard', [
            'connection'     => $connection ? [
                'realm_id'     => $connection->realm_id,
                'environment'  => $connection->environment,
                'connected_at' => $connection->connected_at,
                'expires_at'   => $connection->expires_at,
                'is_expired'   => $connection->isExpired(),
            ] : null,
            'stats'          => $stats,
            'recent'         => $recent,
            'mapping_status' => $mappingStatus,
            'credentials_configured' => $clientId !== '' && $clientSecret !== '',
            'redirect_uri'   => url('/finance/quickbooks/callback'),
        ]);
    }

    public function connect(Request $request)
    {
        $clientId    = (string) config('services.qbo.client_id');
        $redirectUri = url('/finance/quickbooks/callback');
        $environment = $request->query('env', 'sandbox');

        if (! $clientId) {
            return back()->with('error', 'QBO_CLIENT_ID not configured. Add QuickBooks credentials to .env.');
        }

        $state = Str::uuid()->toString();
        session(['qbo_oauth_state' => $state, 'qbo_oauth_env' => $environment]);

        $params = http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
        ]);

        return redirect()->away(self::AUTH_URL . '?' . $params);
    }

    public function callback(Request $request)
    {
        if ($request->state !== session('qbo_oauth_state')) {
            return redirect('/finance/quickbooks')->with('error', 'OAuth state mismatch.');
        }
        $code    = $request->code;
        $realmId = $request->realmId;
        if (! $code || ! $realmId) {
            return redirect('/finance/quickbooks')->with('error', 'Missing code or realmId from QuickBooks.');
        }

        $clientId     = (string) config('services.qbo.client_id');
        $clientSecret = (string) config('services.qbo.client_secret');
        $redirectUri  = url('/finance/quickbooks/callback');

        $resp = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(self::TOKEN_URL, [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
            ]);

        if (! $resp->successful()) {
            return redirect('/finance/quickbooks')->with('error', 'OAuth token exchange failed: ' . $resp->body());
        }

        $data = $resp->json();
        QboConnection::query()->update(['is_active' => false]);
        QboConnection::create([
            'realm_id'      => $realmId,
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at'    => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            'environment'   => strtoupper((string) (session('qbo_oauth_env') ?? 'sandbox')),
            'connected_by'  => $request->user()->id,
            'connected_at'  => now(),
            'is_active'     => true,
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
            $client = new QboClient();
            $data   = $client->query("SELECT Id, Name, AccountType, AccountSubType FROM Account WHERE Active = true ORDERBY Name MAXRESULTS 1000");
            return response()->json($data['QueryResponse']['Account'] ?? []);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function mappings()
    {
        $mappings = QboAccountMapping::all()->keyBy('mapping_key');
        $keys = [
            'inventory_asset'    => 'Inventory Asset',
            'cogs'               => 'Cost of Goods Sold',
            'accounts_payable'   => 'Accounts Payable',
            'bank_account'       => 'Bank Account (deposits)',
            'undeposited_funds'  => 'Undeposited Funds',
            'shipping_expense'   => 'Shipping / Courier Expense',
            'commission_expense' => 'Commission Expense',
            'revenue'            => 'Sales Revenue',
        ];
        return Inertia::render('Finance/QuickBooks/Mappings', [
            'keys'        => $keys,
            'mappings'    => $mappings,
            'qbo_active'  => QboConnection::active() !== null,
        ]);
    }

    public function saveMapping(Request $request)
    {
        $data = $request->validate([
            'mapping_key'      => 'required|string|max:60',
            'qbo_account_id'   => 'required|string|max:60',
            'qbo_account_name' => 'nullable|string|max:200',
        ]);

        QboAccountMapping::updateOrCreate(
            ['mapping_key' => $data['mapping_key']],
            [
                'qbo_account_id'   => $data['qbo_account_id'],
                'qbo_account_name' => $data['qbo_account_name'] ?? null,
                'mapped_by'        => $request->user()->id,
            ],
        );

        return back()->with('success', 'Mapping saved.');
    }
}
