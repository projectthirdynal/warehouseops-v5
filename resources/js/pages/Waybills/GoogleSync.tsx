import { Head, router } from '@inertiajs/react';
import { useState, useEffect, useRef, useCallback } from 'react';
import {
  Sheet,
  CheckCircle,
  XCircle,
  Clock,
  Loader2,
  Link2,
  Unlink,
  Play,
  Save,
  AlertCircle,
  Calendar,
  Truck,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { formatDateTime } from '@/lib/utils';

interface Connection {
  email: string | null;
  connected_at: string;
  expires_at: string | null;
  is_expired: boolean;
}

interface SheetConfig {
  id: number;
  courier: string;
  month: string;
  data_year: number;
  sheet_url: string | null;
  sheet_tab_name: string | null;
  enabled: boolean;
}

interface SyncRun {
  id: number;
  original_filename: string;
  courier: string | null;
  status: string;
  total_rows: number;
  processed_rows: number;
  success_rows: number;
  inserted_rows: number;
  updated_rows: number;
  error_rows: number;
  created_at: string;
  completed_at: string | null;
  uploaded_by: { name: string } | null;
}

interface Props {
  connection: Connection | null;
  configs: SheetConfig[];
  recent_syncs: SyncRun[];
  months: string[];
  couriers: string[];
  current_year: number;
  redirect_uri: string;
  google_configured: boolean;
}

const COURIER_LABELS: Record<string, string> = {
  jnt: 'J&T',
  flash: 'Flash',
  spx: 'SPX',
};

const MONTHS_SHORT: Record<string, string> = {
  January: 'Jan',
  February: 'Feb',
  March: 'Mar',
  April: 'Apr',
  May: 'May',
  June: 'Jun',
  July: 'Jul',
  August: 'Aug',
  September: 'Sep',
  October: 'Oct',
  November: 'Nov',
  December: 'Dec',
};

function getStatusBadge(status: string) {
  const styles: Record<string, string> = {
    completed: 'bg-green-100 text-green-700 border-green-200',
    completed_with_errors: 'bg-yellow-100 text-yellow-700 border-yellow-200',
    processing: 'bg-blue-100 text-blue-700 border-blue-200 animate-pulse',
    queued: 'bg-gray-100 text-gray-600 border-gray-200',
    failed: 'bg-red-100 text-red-700 border-red-200',
    cancelled: 'bg-gray-100 text-gray-500 border-gray-200',
  };
  const label = status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  return <Badge className={styles[status] || 'bg-gray-100 text-gray-600'}>{label}</Badge>;
}

export default function GoogleSync({
  connection,
  configs: initialConfigs,
  recent_syncs: initialSyncs,
  months,
  couriers,
  current_year,
  google_configured,
}: Props) {
  const [configs, setConfigs] = useState<SheetConfig[]>(initialConfigs);
  const [selectedYear, setSelectedYear] = useState(current_year);
  const [selectedMonths, setSelectedMonths] = useState<Set<string>>(new Set(['July', 'August']));
  const [syncing, setSyncing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [liveProgress, setLiveProgress] = useState<Record<number, SyncRun>>({});
  const pollTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Filter configs by selected year
  const yearConfigs = configs.filter((c) => c.data_year === selectedYear);

  // Group configs by courier
  const configsByCourier = couriers.reduce<Record<string, SheetConfig[]>>((acc, courier) => {
    acc[courier] = yearConfigs.filter((c) => c.courier === courier);
    return acc;
  }, {});

  // Ensure all 12 months exist per courier for the selected year
  const ensureConfigs = useCallback(() => {
    setConfigs((prev) => {
      const updated = [...prev];
      couriers.forEach((courier) => {
        months.forEach((month) => {
          const exists = updated.find(
            (c) => c.courier === courier && c.month === month && c.data_year === selectedYear
          );
          if (!exists) {
            updated.push({
              id: 0,
              courier,
              month,
              data_year: selectedYear,
              sheet_url: '',
              sheet_tab_name: '',
              enabled: true,
            });
          }
        });
      });
      return updated;
    });
  }, [couriers, months, selectedYear]);

  useEffect(() => {
    ensureConfigs();
  }, [ensureConfigs]);

  // Poll active sync runs
  const activeSyncs = initialSyncs.filter((s) =>
    ['queued', 'processing', 'pending'].includes(s.status)
  );

  useEffect(() => {
    if (activeSyncs.length === 0) {
      if (pollTimerRef.current) {
        clearInterval(pollTimerRef.current);
        pollTimerRef.current = null;
      }
      return;
    }

    const poll = async () => {
      const updates: Record<number, SyncRun> = {};
      let allDone = true;

      for (const sync of activeSyncs) {
        try {
          const resp = await fetch(`/waybills/sync/run/${sync.id}`, {
            headers: { Accept: 'application/json' },
          });
          if (resp.ok) {
            const data = await resp.json();
            updates[sync.id] = { ...sync, ...data };
            if (['queued', 'processing', 'pending'].includes(data.status)) {
              allDone = false;
            }
          }
        } catch {
          // ignore
        }
      }

      if (Object.keys(updates).length > 0) {
        setLiveProgress((prev) => ({ ...prev, ...updates }));
      }

      if (allDone) {
        if (pollTimerRef.current) {
          clearInterval(pollTimerRef.current);
          pollTimerRef.current = null;
        }
        router.reload({ only: ['recent_syncs'] });
      }
    };

    pollTimerRef.current = setInterval(poll, 3000);
    poll();

    return () => {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeSyncs.length]);

  const updateConfig = (courier: string, month: string, field: keyof SheetConfig, value: any) => {
    setConfigs((prev) =>
      prev.map((c) =>
        c.courier === courier && c.month === month && c.data_year === selectedYear
          ? { ...c, [field]: value }
          : c
      )
    );
  };

  const toggleMonth = (month: string) => {
    setSelectedMonths((prev) => {
      const next = new Set(prev);
      if (next.has(month)) next.delete(month);
      else next.add(month);
      return next;
    });
  };

  const handleSaveConfigs = async () => {
    setSaving(true);
    try {
      const response = await fetch('/waybills/sync/configs', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
        },
        body: JSON.stringify({
          configs: yearConfigs.map((c) => ({
            courier: c.courier,
            month: c.month,
            data_year: c.data_year,
            sheet_url: c.sheet_url,
            sheet_tab_name: c.sheet_tab_name,
            enabled: c.enabled,
          })),
        }),
      });

      if (response.ok) {
        router.reload({ only: ['configs'] });
      }
    } finally {
      setSaving(false);
    }
  };

  const handleSync = async () => {
    if (selectedMonths.size === 0) return;
    setSyncing(true);
    try {
      const response = await fetch('/waybills/sync/run', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
        },
        body: JSON.stringify({
          months: Array.from(selectedMonths),
          data_year: selectedYear,
        }),
      });

      if (response.ok) {
        router.reload({ only: ['recent_syncs'] });
      } else {
        const err = await response.json();
        alert(err.error || 'Sync failed to start');
      }
    } finally {
      setSyncing(false);
    }
  };

  const getProgressPct = (run: SyncRun) => {
    if (
      run.status === 'completed' ||
      run.status === 'completed_with_errors' ||
      run.status === 'failed'
    )
      return 100;
    if (run.total_rows > 0) return Math.round((run.processed_rows / run.total_rows) * 100);
    return null;
  };

  return (
    <AppLayout>
      <Head title="Google Sheet Sync — Waybill Backtracking" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold flex items-center gap-2">
              <Sheet className="h-6 w-6" />
              Google Sheet Sync
            </h1>
            <p className="text-muted-foreground mt-1">
              Automatically sync waybill backtracking data from Google Sheets (J&T, Flash, SPX)
            </p>
          </div>
        </div>

        {!google_configured && (
          <Card className="border-yellow-200 bg-yellow-50">
            <CardContent className="pt-6">
              <div className="flex items-start gap-3">
                <AlertCircle className="h-5 w-5 text-yellow-600 shrink-0 mt-0.5" />
                <div>
                  <p className="font-medium text-yellow-800">Google OAuth not configured</p>
                  <p className="text-sm text-yellow-700 mt-1">
                    Add GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, and GOOGLE_REDIRECT_URI to the .env
                    file on the server, then restart the application.
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Connection Status */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Link2 className="h-5 w-5" />
              Google Account Connection
            </CardTitle>
            <CardDescription>
              Connect a Google account to read Sheet data server-side
            </CardDescription>
          </CardHeader>
          <CardContent>
            {connection ? (
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="h-10 w-10 rounded-full bg-green-100 flex items-center justify-center">
                    <CheckCircle className="h-6 w-6 text-green-600" />
                  </div>
                  <div>
                    <p className="font-medium">{connection.email || 'Connected'}</p>
                    <p className="text-sm text-muted-foreground">
                      Connected {formatDateTime(connection.connected_at)}
                      {connection.is_expired && (
                        <span className="text-yellow-600 ml-2">
                          (token expired — will refresh on next sync)
                        </span>
                      )}
                    </p>
                  </div>
                </div>
                <form action="/waybills/sync/disconnect" method="POST">
                  <input
                    type="hidden"
                    name="_token"
                    value={
                      (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                        ?.content || ''
                    }
                  />
                  <Button type="submit" variant="outline" size="sm">
                    <Unlink className="h-4 w-4 mr-1.5" />
                    Disconnect
                  </Button>
                </form>
              </div>
            ) : (
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center">
                    <XCircle className="h-6 w-6 text-gray-400" />
                  </div>
                  <div>
                    <p className="font-medium">Not connected</p>
                    <p className="text-sm text-muted-foreground">
                      Connect your Google account to enable Sheet sync
                    </p>
                  </div>
                </div>
                <a href="/waybills/sync/connect">
                  <Button disabled={!google_configured}>
                    <Link2 className="h-4 w-4 mr-1.5" />
                    Connect Google Account
                  </Button>
                </a>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Sync Controls */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Calendar className="h-5 w-5" />
              Sync Controls
            </CardTitle>
            <CardDescription>Select months to update and trigger sync</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* Year selector */}
            <div className="flex items-center gap-3">
              <Label className="text-sm">Data Year:</Label>
              <Select
                value={String(selectedYear)}
                onValueChange={(v) => setSelectedYear(Number(v))}
              >
                <SelectTrigger className="w-32">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Array.from({ length: 7 }, (_, i) => current_year - 3 + i).map((year) => (
                    <SelectItem key={year} value={String(year)}>
                      {year}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Month picker */}
            <div>
              <div className="flex items-center justify-between mb-2">
                <Label className="text-sm font-medium">Months to Update</Label>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setSelectedMonths(new Set(['July', 'August']))}
                  >
                    Jul & Aug
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setSelectedMonths(new Set(months))}
                  >
                    Select All
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => setSelectedMonths(new Set())}>
                    Clear
                  </Button>
                </div>
              </div>
              <div className="grid grid-cols-6 gap-2">
                {months.map((month) => (
                  <label
                    key={month}
                    className={`flex items-center gap-2 border rounded-lg p-2 cursor-pointer transition-colors ${
                      selectedMonths.has(month)
                        ? 'border-blue-500 bg-blue-50 text-blue-700'
                        : 'border-gray-200 hover:bg-gray-50'
                    }`}
                  >
                    <Checkbox
                      checked={selectedMonths.has(month)}
                      onCheckedChange={() => toggleMonth(month)}
                    />
                    <span className="text-sm font-medium">{MONTHS_SHORT[month] || month}</span>
                  </label>
                ))}
              </div>
              <p className="text-xs text-muted-foreground mt-2">
                Only selected months will be re-read. Unchecked months retain their existing data.
              </p>
            </div>

            {/* Action buttons */}
            <div className="flex gap-3 pt-2">
              <Button onClick={handleSaveConfigs} disabled={saving || !connection}>
                {saving ? (
                  <Loader2 className="h-4 w-4 mr-1.5 animate-spin" />
                ) : (
                  <Save className="h-4 w-4 mr-1.5" />
                )}
                Save Settings & Links
              </Button>
              <Button
                onClick={handleSync}
                disabled={syncing || !connection || selectedMonths.size === 0}
              >
                {syncing ? (
                  <Loader2 className="h-4 w-4 mr-1.5 animate-spin" />
                ) : (
                  <Play className="h-4 w-4 mr-1.5" />
                )}
                Sync Selected Months
              </Button>
            </div>
          </CardContent>
        </Card>

        {/* Sheet URL Configuration */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Truck className="h-5 w-5" />
              Monthly Google Sheet Links — {selectedYear}
            </CardTitle>
            <CardDescription>
              Paste the Google Sheet link for each courier and month. Sheets must be shared as
              "Anyone with the link — Viewer" or the connected Google account must have access.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            {couriers.map((courier) => (
              <div key={courier}>
                <h3 className="text-sm font-semibold mb-3 flex items-center gap-2">
                  <Badge variant="outline" className="uppercase">
                    {COURIER_LABELS[courier] || courier}
                  </Badge>
                  <span className="text-muted-foreground">January–December {selectedYear}</span>
                </h3>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                  {(configsByCourier[courier] || []).map((cfg) => (
                    <div
                      key={`${cfg.courier}-${cfg.month}-${cfg.data_year}`}
                      className={`border rounded-lg p-3 space-y-2 ${!cfg.enabled ? 'opacity-60' : ''}`}
                    >
                      <div className="flex items-center justify-between">
                        <span className="text-sm font-medium">
                          {cfg.month} {cfg.data_year}
                        </span>
                        <label className="flex items-center gap-1.5 cursor-pointer">
                          <Checkbox
                            checked={cfg.enabled}
                            onCheckedChange={(v) =>
                              updateConfig(cfg.courier, cfg.month, 'enabled', v === true)
                            }
                          />
                          <span className="text-xs text-muted-foreground">Include</span>
                        </label>
                      </div>
                      <Input
                        type="url"
                        placeholder={`${cfg.month} ${cfg.data_year} ${COURIER_LABELS[courier]} Sheet link`}
                        value={cfg.sheet_url || ''}
                        onChange={(e) =>
                          updateConfig(cfg.courier, cfg.month, 'sheet_url', e.target.value)
                        }
                        disabled={!cfg.enabled}
                        className="text-xs h-9"
                      />
                      <Input
                        type="text"
                        placeholder="Sheet tab name (blank = first tab)"
                        value={cfg.sheet_tab_name || ''}
                        onChange={(e) =>
                          updateConfig(cfg.courier, cfg.month, 'sheet_tab_name', e.target.value)
                        }
                        disabled={!cfg.enabled}
                        className="text-xs h-9"
                      />
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        {/* Recent Sync Runs */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Clock className="h-5 w-5" />
              Recent Sync Runs
            </CardTitle>
            <CardDescription>Sync history and live progress</CardDescription>
          </CardHeader>
          <CardContent>
            {initialSyncs.length === 0 && Object.keys(liveProgress).length === 0 ? (
              <div className="text-center py-8">
                <Sheet className="mx-auto h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-muted-foreground">No sync runs yet</p>
              </div>
            ) : (
              <div className="space-y-3">
                {initialSyncs.map((sync) => {
                  const live = liveProgress[sync.id];
                  const display = live || sync;
                  const isActive = ['queued', 'processing', 'pending'].includes(display.status);
                  const pct = getProgressPct(display);

                  return (
                    <div key={sync.id} className="border rounded-lg p-4 space-y-2">
                      <div className="flex items-start justify-between gap-3">
                        <div className="flex items-center gap-3 min-w-0">
                          <Sheet className="h-8 w-8 text-blue-500 shrink-0" />
                          <div className="min-w-0">
                            <p className="font-medium truncate">{display.original_filename}</p>
                            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-muted-foreground">
                              {display.total_rows > 0 && (
                                <span>{display.total_rows.toLocaleString()} rows</span>
                              )}
                              {display.inserted_rows > 0 && (
                                <>
                                  <span>|</span>
                                  <span className="text-green-600">
                                    {display.inserted_rows.toLocaleString()} ins
                                  </span>
                                </>
                              )}
                              {display.updated_rows > 0 && (
                                <>
                                  <span>|</span>
                                  <span className="text-blue-600">
                                    {display.updated_rows.toLocaleString()} upd
                                  </span>
                                </>
                              )}
                              {display.error_rows > 0 && (
                                <>
                                  <span>|</span>
                                  <span className="text-red-600">
                                    {display.error_rows.toLocaleString()} err
                                  </span>
                                </>
                              )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                              {formatDateTime(display.created_at)}
                              {display.uploaded_by && ` by ${display.uploaded_by.name}`}
                            </p>
                          </div>
                        </div>
                        {getStatusBadge(display.status)}
                      </div>

                      {isActive && (
                        <div className="space-y-1.5">
                          <div className="flex justify-between text-xs text-muted-foreground">
                            <span>
                              {display.processed_rows > 0
                                ? `${display.processed_rows.toLocaleString()} rows processed`
                                : 'Queued — waiting to start…'}
                            </span>
                            {pct !== null && <span>{pct}%</span>}
                          </div>
                          <Progress
                            value={pct ?? 0}
                            className={`h-1.5 ${pct === null ? 'animate-pulse' : ''}`}
                          />
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
