import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Truck,
  Wifi,
  WifiOff,
  RefreshCw,
  CheckCircle,
  XCircle,
  Clock,
  ExternalLink,
  Activity,
  Loader2,
  FileText,
} from 'lucide-react';
import { formatRelativeTime } from '@/lib/utils';
import type { CourierProvider, CourierApiLog, PageProps } from '@/types';

interface Props extends PageProps {
  providers: CourierProvider[];
  recentLogs: CourierApiLog[];
}

export default function CouriersIndex({ providers, recentLogs }: Props) {
  const [testing, setTesting] = useState<number | null>(null);
  const [testResults, setTestResults] = useState<Record<number, { connected: boolean; message: string }>>({});
  const [syncing, setSyncing] = useState<number | null>(null);

  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

  const testConnection = async (provider: CourierProvider) => {
    setTesting(provider.id);
    try {
      const res = await fetch(`/couriers/${provider.id}/test`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
      });
      const result = await res.json();
      setTestResults((prev) => ({ ...prev, [provider.id]: result }));
    } catch {
      setTestResults((prev) => ({
        ...prev,
        [provider.id]: { connected: false, message: 'Request failed' },
      }));
    } finally {
      setTesting(null);
    }
  };

  const syncTracking = async (provider: CourierProvider) => {
    setSyncing(provider.id);
    try {
      await fetch(`/couriers/${provider.id}/sync`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
      });
    } finally {
      setSyncing(null);
    }
  };

  const toggleActive = (provider: CourierProvider) => {
    router.patch(`/couriers/${provider.id}`, { is_active: !provider.is_active });
  };

  return (
    <AppLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold flex items-center gap-2">
              <Truck className="h-6 w-6" />
              Courier Integrations
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              Manage courier API connections for order creation, tracking, and webhooks
            </p>
          </div>
        </div>

        {/* Provider Cards */}
        <div className="grid gap-4 md:grid-cols-2">
          {providers.map((provider) => {
            const test = testResults[provider.id];

            return (
              <div
                key={provider.id}
                className="rounded-xl border bg-card shadow-sm overflow-hidden"
              >
                {/* Card header */}
                <div className="flex items-center justify-between p-5 border-b bg-muted/30">
                  <div className="flex items-center gap-3">
                    <div className={`flex h-10 w-10 items-center justify-center rounded-xl shadow-sm ${
                      provider.is_active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
                    }`}>
                      <Truck className="h-5 w-5" />
                    </div>
                    <div>
                      <h3 className="font-semibold text-base">{provider.name}</h3>
                      <p className="text-xs text-muted-foreground font-mono">{provider.code}</p>
                    </div>
                  </div>
                  <Badge variant={provider.is_active ? 'default' : 'secondary'}>
                    {provider.is_active ? 'Active' : 'Inactive'}
                  </Badge>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-3 gap-px bg-border">
                  <div className="bg-card p-3 text-center">
                    <p className="text-lg font-bold">{provider.active_waybills ?? 0}</p>
                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Active</p>
                  </div>
                  <div className="bg-card p-3 text-center">
                    <p className="text-lg font-bold">{provider.total_api_calls ?? 0}</p>
                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">API Calls</p>
                  </div>
                  <div className="bg-card p-3 text-center">
                    <p className="text-lg font-bold text-red-500">{provider.failed_api_calls ?? 0}</p>
                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Failed</p>
                  </div>
                </div>

                {/* Details */}
                <div className="p-4 space-y-3 text-sm">
                  {provider.api_endpoint && (
                    <div className="flex items-center gap-2 text-muted-foreground">
                      <ExternalLink className="h-3.5 w-3.5 shrink-0" />
                      <span className="truncate font-mono text-xs">{provider.api_endpoint}</span>
                    </div>
                  )}
                  {provider.last_api_call_at && (
                    <div className="flex items-center gap-2 text-muted-foreground">
                      <Clock className="h-3.5 w-3.5 shrink-0" />
                      <span>Last API call: {formatRelativeTime(provider.last_api_call_at)}</span>
                    </div>
                  )}

                  {/* Connection test result */}
                  {test && (
                    <div className={`flex items-center gap-2 p-2 rounded-lg text-xs ${
                      test.connected ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
                    }`}>
                      {test.connected ? <Wifi className="h-3.5 w-3.5" /> : <WifiOff className="h-3.5 w-3.5" />}
                      {test.message}
                    </div>
                  )}
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2 p-4 pt-0">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => testConnection(provider)}
                    disabled={testing === provider.id}
                  >
                    {testing === provider.id ? (
                      <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />
                    ) : (
                      <Activity className="mr-1 h-3.5 w-3.5" />
                    )}
                    Test
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => syncTracking(provider)}
                    disabled={syncing === provider.id}
                  >
                    {syncing === provider.id ? (
                      <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />
                    ) : (
                      <RefreshCw className="mr-1 h-3.5 w-3.5" />
                    )}
                    Sync
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => router.visit(`/couriers/${provider.id}/logs`)}
                  >
                    <FileText className="mr-1 h-3.5 w-3.5" />
                    Logs
                  </Button>
                  <Button
                    variant={provider.is_active ? 'destructive' : 'default'}
                    size="sm"
                    className="ml-auto"
                    onClick={() => toggleActive(provider)}
                  >
                    {provider.is_active ? 'Disable' : 'Enable'}
                  </Button>
                </div>
              </div>
            );
          })}
        </div>

        {/* Recent API Logs */}
        <div className="rounded-xl border bg-card shadow-sm">
          <div className="flex items-center gap-2 p-5 border-b">
            <Activity className="h-5 w-5 text-muted-foreground" />
            <h2 className="font-semibold">Recent API Activity</h2>
          </div>
          <div className="divide-y">
            {recentLogs.length === 0 ? (
              <div className="p-8 text-center text-sm text-muted-foreground">
                No API activity yet. Test a connection or submit an order to get started.
              </div>
            ) : (
              recentLogs.map((log) => (
                <div key={log.id} className="flex items-center gap-3 px-5 py-3 text-sm">
                  {log.is_success ? (
                    <CheckCircle className="h-4 w-4 text-green-500 shrink-0" />
                  ) : (
                    <XCircle className="h-4 w-4 text-red-500 shrink-0" />
                  )}
                  <Badge variant="outline" className="text-xs font-mono shrink-0">
                    {log.courier_code}
                  </Badge>
                  <span className="font-medium">{log.action.replace(/_/g, ' ')}</span>
                  <Badge variant={log.direction === 'inbound' ? 'secondary' : 'outline'} className="text-[10px]">
                    {log.direction}
                  </Badge>
                  {log.http_status && (
                    <span className={`text-xs font-mono ${log.http_status >= 400 ? 'text-red-500' : 'text-muted-foreground'}`}>
                      {log.http_status}
                    </span>
                  )}
                  {log.response_time_ms && (
                    <span className="text-xs text-muted-foreground">{log.response_time_ms}ms</span>
                  )}
                  <span className="ml-auto text-xs text-muted-foreground shrink-0">
                    {formatRelativeTime(log.created_at)}
                  </span>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
