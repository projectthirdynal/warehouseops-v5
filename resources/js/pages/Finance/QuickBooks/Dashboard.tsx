import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { Building2, RefreshCw, CheckCircle2, AlertCircle, XCircle, Clock, Power, Settings, KeyRound } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Connection {
  realm_id: string;
  environment: string;
  connected_at: string;
  expires_at: string;
  is_expired: boolean;
}

interface SyncRow {
  id: number;
  entity_type: string;
  entity_id: number;
  operation: string;
  status: string;
  qbo_id?: string;
  attempts: number;
  error_message?: string;
  synced_at?: string;
  created_at: string;
}

interface Props {
  connection: Connection | null;
  stats: { pending: number; failed: number; synced: number };
  recent: SyncRow[];
  mapping_status: Record<string, string | null>;
  credentials_configured: boolean;
  redirect_uri: string;
}

const STATUS_COLOR: Record<string, string> = {
  PENDING: 'bg-blue-100 text-blue-700',
  SYNCED:  'bg-green-100 text-green-700',
  FAILED:  'bg-red-100 text-red-700',
};

export default function QuickBooksDashboard({ connection, stats, recent, mapping_status, credentials_configured, redirect_uri }: Props) {
  const flash = (usePage<PageProps & { flash?: { error?: string; success?: string } }>().props.flash) ?? {};
  const missingMappings = Object.entries(mapping_status).filter(([, v]) => !v).map(([k]) => k);

  function retry(id: number) {
    router.post(`/finance/quickbooks/sync/${id}/retry`);
  }
  function disconnect() {
    if (window.confirm('Disconnect QuickBooks? Pending syncs will not retry until reconnected.')) {
      router.post('/finance/quickbooks/disconnect');
    }
  }

  return (
    <AppLayout>
      <Head title="QuickBooks Online" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">QuickBooks Online</h1>
            <p className="text-sm text-muted-foreground">Async sync of financial events to QBO with idempotent retries.</p>
          </div>
          {connection ? (
            <Button variant="outline" onClick={disconnect}><Power className="mr-2 h-4 w-4" />Disconnect</Button>
          ) : (
            <div className="flex gap-2">
              <Button variant="outline" disabled={!credentials_configured} onClick={() => { window.location.href = '/finance/quickbooks/connect?env=sandbox'; }}>
                <Building2 className="mr-2 h-4 w-4" />Connect Sandbox
              </Button>
              <Button disabled={!credentials_configured} onClick={() => { window.location.href = '/finance/quickbooks/connect?env=production'; }}>
                <Building2 className="mr-2 h-4 w-4" />Connect Production
              </Button>
            </div>
          )}
        </div>

        {/* Flash error from controller */}
        {flash.error && (
          <div className="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <XCircle className="h-5 w-5 shrink-0" />
            <span>{flash.error}</span>
          </div>
        )}
        {flash.success && (
          <div className="flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            <CheckCircle2 className="h-5 w-5 shrink-0" />
            <span>{flash.success}</span>
          </div>
        )}

        {/* Credentials missing — must come before connect can work */}
        {!credentials_configured && !connection && (
          <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-4">
            <div className="flex items-start gap-3">
              <KeyRound className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
              <div className="flex-1 text-sm text-amber-900">
                <p className="font-semibold">QuickBooks credentials are not configured</p>
                <p className="mt-1">
                  Before you can connect, an admin must register this app on{' '}
                  <a className="underline" href="https://developer.intuit.com/app/developer/dashboard" target="_blank" rel="noreferrer">developer.intuit.com</a>{' '}
                  and add these to the server <code className="rounded bg-amber-100 px-1 font-mono">.env</code>:
                </p>
                <pre className="mt-2 overflow-x-auto rounded bg-amber-100 p-2 font-mono text-xs">
{`QBO_CLIENT_ID=your_client_id
QBO_CLIENT_SECRET=your_client_secret
QBO_ENVIRONMENT=sandbox    # or "production"`}
                </pre>
                <p className="mt-2">When registering the QuickBooks app, set the redirect URI to:</p>
                <p className="mt-1 break-all rounded bg-amber-100 p-2 font-mono text-xs">{redirect_uri}</p>
                <p className="mt-2">After saving, run <code className="rounded bg-amber-100 px-1 font-mono">php artisan config:clear</code> on the server, then refresh this page.</p>
              </div>
            </div>
          </div>
        )}

        {/* Connection card */}
        <Card>
          <CardHeader className="pb-2"><CardTitle className="text-base">Connection</CardTitle></CardHeader>
          <CardContent>
            {connection ? (
              <div className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                <Detail label="Environment" value={connection.environment} />
                <Detail label="Realm ID" value={connection.realm_id} mono />
                <Detail label="Connected" value={formatDate(connection.connected_at)} />
                <Detail label="Token expires"
                  value={formatDate(connection.expires_at)}
                  highlight={connection.is_expired ? 'red' : undefined} />
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">Not connected. Click "Connect" above to authorize this app on QuickBooks.</p>
            )}
          </CardContent>
        </Card>

        {/* Mapping warnings */}
        {connection && missingMappings.length > 0 && (
          <div className="flex items-center gap-3 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3">
            <AlertCircle className="h-5 w-5 shrink-0 text-yellow-600" />
            <div className="flex-1 text-sm text-yellow-900">
              {missingMappings.length} required QBO account mapping{missingMappings.length !== 1 ? 's' : ''} not configured: <span className="font-mono text-xs">{missingMappings.join(', ')}</span>
            </div>
            <Link href="/finance/quickbooks/mappings"><Button size="sm"><Settings className="mr-1 h-3.5 w-3.5" />Map accounts</Button></Link>
          </div>
        )}

        {/* Stats */}
        <div className="grid grid-cols-3 gap-4">
          <Stat icon={<Clock className="h-4 w-4 text-blue-500" />}        label="Pending Sync" value={stats.pending} />
          <Stat icon={<CheckCircle2 className="h-4 w-4 text-green-500" />} label="Synced"       value={stats.synced} />
          <Stat icon={<XCircle className="h-4 w-4 text-red-500" />}        label="Failed"       value={stats.failed} />
        </div>

        {/* Recent sync queue */}
        <Card>
          <CardHeader className="pb-2"><CardTitle className="text-base">Recent Sync Activity</CardTitle></CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>When</TableHead>
                  <TableHead>Entity</TableHead>
                  <TableHead>Op</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Tries</TableHead>
                  <TableHead>QBO ID</TableHead>
                  <TableHead>Error</TableHead>
                  <TableHead></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {recent.length === 0 ? (
                  <TableRow><TableCell colSpan={8} className="py-12 text-center text-muted-foreground">No sync activity yet.</TableCell></TableRow>
                ) : recent.map(r => (
                  <TableRow key={r.id}>
                    <TableCell className="text-xs text-muted-foreground whitespace-nowrap">{formatDate(r.created_at)}</TableCell>
                    <TableCell className="text-sm">
                      <span className="font-mono">{r.entity_type}</span>
                      <span className="ml-1 text-xs text-muted-foreground">#{r.entity_id}</span>
                    </TableCell>
                    <TableCell className="text-xs">{r.operation}</TableCell>
                    <TableCell><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLOR[r.status]}`}>{r.status}</span></TableCell>
                    <TableCell className="text-right text-sm">{r.attempts}</TableCell>
                    <TableCell className="font-mono text-xs">{r.qbo_id ?? '—'}</TableCell>
                    <TableCell className="max-w-xs truncate text-xs text-red-600">{r.error_message ?? ''}</TableCell>
                    <TableCell>
                      {r.status === 'FAILED' && (
                        <Button size="sm" variant="outline" onClick={() => retry(r.id)}>
                          <RefreshCw className="mr-1 h-3 w-3" />Retry
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}

function Stat({ icon, label, value }: { icon: React.ReactNode; label: string; value: number }) {
  return (
    <Card>
      <CardHeader className="pb-2"><CardTitle className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</CardTitle></CardHeader>
      <CardContent><div className="flex items-center gap-2">{icon}<span className="text-2xl font-bold">{value}</span></div></CardContent>
    </Card>
  );
}

function Detail({ label, value, mono, highlight }: { label: string; value: string; mono?: boolean; highlight?: 'red' }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`mt-0.5 ${mono ? 'font-mono text-xs' : 'text-sm font-medium'} ${highlight === 'red' ? 'text-red-600' : ''}`}>{value}</p>
    </div>
  );
}
