import { Head, router } from '@inertiajs/react';
import { useState, useRef, useEffect, useCallback } from 'react';
import {
  FileSpreadsheet,
  CheckCircle,
  XCircle,
  Clock,
  RefreshCw,
  AlertCircle,
  Loader2,
  Eye,
  StopCircle,
  Ban,
  Link2,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { formatDateTime } from '@/lib/utils';

interface UploadRecord {
  id: number;
  filename: string;
  original_filename: string;
  courier: string | null;
  import_type: string | null;
  total_rows: number;
  processed_rows: number;
  success_rows: number;
  inserted_rows: number;
  updated_rows: number;
  skipped_rows: number;
  error_rows: number;
  status:
    | 'queued'
    | 'validating'
    | 'validation_failed'
    | 'ready_to_process'
    | 'pending'
    | 'processing'
    | 'completed'
    | 'completed_with_errors'
    | 'failed'
    | 'cancelled';
  errors: Array<{ row: number; error: string }> | null;
  uploaded_by: { name: string } | null;
  created_at: string;
}

interface UploadProgress {
  id: number;
  status: string;
  courier: string | null;
  import_type: string | null;
  total_rows: number;
  processed_rows: number;
  success_rows: number;
  inserted_rows: number;
  updated_rows: number;
  skipped_rows: number;
  error_rows: number;
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

interface Props {
  uploads: {
    data: UploadRecord[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
  stats: {
    total_uploads: number;
    total_imported: number;
    pending_uploads: number;
    recent_errors: number;
  };
  sheet_configs: SheetConfig[];
}

const COURIER_LABELS: Record<string, string> = {
  jnt: 'J&T Express',
  flash: 'Flash Express',
  spx: 'SPX Express',
};

const MONTHS = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];

const formatImportType = (type: string | null) => {
  if (!type) return '';
  if (type === 'auto_sync') return 'Auto Sync';
  if (type === 'google_sync') return 'Google Sync';
  return type
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
};

export default function WaybillImport({ uploads, stats, sheet_configs }: Props) {
  const pollTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const [selectedCourier, setSelectedCourier] = useState<string>('jnt');
  const [selectedMonth, setSelectedMonth] = useState<string>(MONTHS[new Date().getMonth()]);
  const [selectedYear, setSelectedYear] = useState<number>(new Date().getFullYear());
  const [sheetUrl, setSheetUrl] = useState<string>('');
  const [sheetTabName, setSheetTabName] = useState<string>('');
  const [syncing, setSyncing] = useState(false);
  const [syncError, setSyncError] = useState<string | null>(null);
  const [liveProgress, setLiveProgress] = useState<Record<number, UploadProgress>>({});

  // Load saved sheet URL for selected courier/month/year
  useEffect(() => {
    const config = sheet_configs.find(
      (c) =>
        c.courier === selectedCourier && c.month === selectedMonth && c.data_year === selectedYear
    );
    setSheetUrl(config?.sheet_url ?? '');
    setSheetTabName(config?.sheet_tab_name ?? '');
  }, [selectedCourier, selectedMonth, selectedYear, sheet_configs]);

  const processingIds = uploads.data
    .filter((u) => ['pending', 'processing', 'queued', 'validating'].includes(u.status))
    .map((u) => u.id);

  const pollStatus = useCallback(async (id: number) => {
    try {
      const res = await fetch(`/waybills/import/${id}/status`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!res.ok) return;
      const data: UploadProgress = await res.json();
      setLiveProgress((prev) => ({ ...prev, [id]: data }));

      if (
        ['completed', 'completed_with_errors', 'failed', 'cancelled', 'validation_failed'].includes(
          data.status
        )
      ) {
        router.reload({ only: ['uploads', 'stats'] });
      }
    } catch {
      // ignore transient network errors
    }
  }, []);

  const processingIdsKey = processingIds.join(',');

  useEffect(() => {
    if (processingIds.length === 0) {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
      return;
    }

    processingIds.forEach(pollStatus);
    pollTimerRef.current = setInterval(() => {
      processingIds.forEach(pollStatus);
    }, 3000);

    return () => {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- processingIdsKey is the stable representation of processingIds
  }, [processingIdsKey, pollStatus]);

  const handleSync = async () => {
    if (!sheetUrl.trim()) {
      setSyncError('Please enter a Google Sheet URL.');
      return;
    }

    setSyncing(true);
    setSyncError(null);

    try {
      const csrfToken =
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
      const res = await fetch('/waybills/import/sync-sheet', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          courier: selectedCourier,
          sheet_url: sheetUrl.trim(),
          sheet_tab_name: sheetTabName.trim() || null,
          month: selectedMonth,
          data_year: selectedYear,
        }),
      });

      const data = await res.json();
      if (!res.ok) {
        setSyncError(data.error ?? data.message ?? 'Sync failed.');
        return;
      }

      // Reload to show the new upload in the list
      router.reload({ only: ['uploads', 'stats', 'sheet_configs'] });
    } catch {
      setSyncError('Network error. Please try again.');
    } finally {
      setSyncing(false);
    }
  };

  const handleSaveUrl = async () => {
    const csrfToken =
      (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
    await fetch('/waybills/import/save-sheet-config', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        courier: selectedCourier,
        sheet_url: sheetUrl.trim() || null,
        sheet_tab_name: sheetTabName.trim() || null,
        month: selectedMonth,
        data_year: selectedYear,
        enabled: true,
      }),
    });
    router.reload({ only: ['sheet_configs'] });
  };

  const handleRetry = (uploadId: number) => {
    router.post(`/waybills/import/${uploadId}/retry`);
  };

  const handleCancel = (uploadId: number) => {
    if (confirm('Stop this import? Already-imported waybills will remain in the system.')) {
      router.post(`/waybills/import/${uploadId}/cancel`);
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'completed':
        return (
          <Badge className="bg-success/10 text-success border-success/20">
            <CheckCircle className="w-3 h-3 mr-1" /> Completed
          </Badge>
        );
      case 'completed_with_errors':
        return (
          <Badge className="bg-warning/10 text-warning border-warning/20">
            <AlertCircle className="w-3 h-3 mr-1" /> Completed with Errors
          </Badge>
        );
      case 'processing':
        return (
          <Badge className="bg-info/10 text-info border-info/20">
            <Loader2 className="w-3 h-3 mr-1 animate-spin" /> Processing
          </Badge>
        );
      case 'validating':
        return (
          <Badge className="bg-warning/10 text-warning border-warning/20">
            <Loader2 className="w-3 h-3 mr-1 animate-spin" /> Validating
          </Badge>
        );
      case 'queued':
        return (
          <Badge className="bg-muted text-muted-foreground">
            <Clock className="w-3 h-3 mr-1" /> Queued
          </Badge>
        );
      case 'failed':
        return (
          <Badge className="bg-destructive/10 text-destructive border-destructive/20">
            <XCircle className="w-3 h-3 mr-1" /> Failed
          </Badge>
        );
      case 'cancelled':
        return (
          <Badge className="bg-muted text-muted-foreground">
            <Ban className="w-3 h-3 mr-1" /> Cancelled
          </Badge>
        );
      case 'validation_failed':
        return (
          <Badge className="bg-destructive/10 text-destructive border-destructive/20">
            <XCircle className="w-3 h-3 mr-1" /> Validation Failed
          </Badge>
        );
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  const getCourierBadge = (courier: string | null) => {
    if (!courier) return null;
    const colors: Record<string, string> = {
      jnt: 'bg-red-100 text-red-700 border-red-200',
      flash: 'bg-orange-100 text-orange-700 border-orange-200',
      spx: 'bg-blue-100 text-blue-700 border-blue-200',
    };
    return (
      <Badge className={colors[courier] ?? 'bg-muted text-muted-foreground'}>
        {COURIER_LABELS[courier] ?? courier.toUpperCase()}
      </Badge>
    );
  };

  const getProgressPct = (upload: UploadRecord): number => {
    const live = liveProgress[upload.id];
    const total = live?.total_rows ?? upload.total_rows;
    const processed = live?.processed_rows ?? upload.processed_rows;
    if (total === 0) return 0;
    return Math.min(100, Math.round((processed / total) * 100));
  };

  const isProcessing = (upload: UploadRecord): boolean => {
    const live = liveProgress[upload.id];
    const status = live?.status ?? upload.status;
    return ['processing', 'queued', 'validating', 'pending'].includes(status);
  };

  return (
    <AppLayout>
      <Head title="Import Waybills" />

      <div className="space-y-4">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">Import Waybills</h1>
            <p className="text-muted-foreground">
              Sync waybills from Google Sheets. Existing waybills update automatically; new waybills
              are added. Re-sync to pick up newly appended rows.
            </p>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Total Imports</CardTitle>
              <RefreshCw className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{stats.total_uploads}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Waybills Imported</CardTitle>
              <FileSpreadsheet className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">
                {stats.total_imported.toLocaleString()}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Processing</CardTitle>
              <Clock className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{stats.pending_uploads}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Recent Errors</CardTitle>
              <AlertCircle className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-destructive">
                {stats.recent_errors}
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          {/* Google Sheet Sync Panel */}
          <div className="lg:col-span-1 space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Link2 className="h-5 w-5" />
                  Google Sheet Sync
                </CardTitle>
                <CardDescription>
                  Paste a shared Google Sheet link and sync. Re-sync to update statuses and pick up
                  new rows.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {/* Courier selection */}
                <div className="space-y-2">
                  <Label>Courier Provider</Label>
                  <Select
                    value={selectedCourier}
                    onValueChange={setSelectedCourier}
                    disabled={syncing}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select courier" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="jnt">J&T Express</SelectItem>
                      <SelectItem value="flash">Flash Express</SelectItem>
                      <SelectItem value="spx">SPX Express</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                {/* Month selection */}
                <div className="space-y-2">
                  <Label>Month</Label>
                  <Select value={selectedMonth} onValueChange={setSelectedMonth} disabled={syncing}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select month" />
                    </SelectTrigger>
                    <SelectContent>
                      {MONTHS.map((m) => (
                        <SelectItem key={m} value={m}>
                          {m}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {/* Year selection */}
                <div className="space-y-2">
                  <Label>Year</Label>
                  <Select
                    value={String(selectedYear)}
                    onValueChange={(v) => setSelectedYear(Number(v))}
                    disabled={syncing}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select year" />
                    </SelectTrigger>
                    <SelectContent>
                      {[selectedYear - 1, selectedYear, selectedYear + 1].map((y) => (
                        <SelectItem key={y} value={String(y)}>
                          {y}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {/* Sheet URL input */}
                <div className="space-y-2">
                  <Label>Google Sheet URL</Label>
                  <Input
                    type="url"
                    placeholder="https://docs.google.com/spreadsheets/d/..."
                    value={sheetUrl}
                    onChange={(e) => setSheetUrl(e.target.value)}
                    disabled={syncing}
                  />
                  <p className="text-xs text-muted-foreground">
                    Sheet must be shared as &ldquo;Anyone with the link — Viewer&rdquo;
                  </p>
                </div>

                {/* Sheet tab name (optional) */}
                <div className="space-y-2">
                  <Label>Sheet Tab Name (optional)</Label>
                  <Input
                    type="text"
                    placeholder="Leave blank to use first tab"
                    value={sheetTabName}
                    onChange={(e) => setSheetTabName(e.target.value)}
                    disabled={syncing}
                  />
                </div>

                {/* Error message */}
                {syncError && (
                  <div className="flex items-start gap-2 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                    <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
                    <span>{syncError}</span>
                  </div>
                )}

                {/* Action buttons */}
                <div className="flex gap-2">
                  <Button
                    onClick={handleSync}
                    disabled={syncing || !sheetUrl.trim()}
                    className="flex-1"
                  >
                    {syncing ? (
                      <>
                        <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        Syncing...
                      </>
                    ) : (
                      <>
                        <RefreshCw className="h-4 w-4 mr-2" />
                        Sync Now
                      </>
                    )}
                  </Button>
                  <Button variant="outline" onClick={handleSaveUrl} disabled={syncing}>
                    Save URL
                  </Button>
                </div>

                {/* Info notice */}
                <div className="rounded-md bg-info/5 border border-info/20 p-3 text-xs text-muted-foreground">
                  <p className="font-medium text-foreground mb-1">How it works:</p>
                  <ul className="space-y-1 list-disc list-inside">
                    <li>System reads the entire sheet and imports all waybill rows</li>
                    <li>
                      Re-syncing updates statuses for changed waybills (inserted/updated/skipped)
                    </li>
                    <li>New rows appended to the sheet are picked up on next sync</li>
                    <li>Changing the URL for next month leaves previous data intact</li>
                  </ul>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Upload History */}
          <div className="lg:col-span-2 space-y-4">
            <Card>
              <CardHeader>
                <CardTitle>Import History</CardTitle>
                <CardDescription>Recent sync and import runs</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {uploads.data.length === 0 ? (
                    <div className="text-center py-12 text-muted-foreground">
                      <FileSpreadsheet className="mx-auto h-12 w-12 mb-3 opacity-50" />
                      <p>No imports yet. Paste a Google Sheet URL to get started.</p>
                    </div>
                  ) : (
                    uploads.data.map((upload) => {
                      const live = liveProgress[upload.id];
                      const status = live?.status ?? upload.status;
                      const processing = isProcessing(upload);
                      const pct = getProgressPct(upload);

                      return (
                        <div
                          key={upload.id}
                          className="border rounded-lg p-4 space-y-3 hover:bg-muted/30 transition-colors"
                        >
                          <div className="flex items-start justify-between gap-3">
                            <div className="space-y-1 min-w-0 flex-1">
                              <div className="flex items-center gap-2 flex-wrap">
                                {getCourierBadge(upload.courier)}
                                {upload.import_type && (
                                  <Badge variant="outline" className="text-xs">
                                    {formatImportType(upload.import_type)}
                                  </Badge>
                                )}
                                {getStatusBadge(status)}
                              </div>
                              <p className="text-sm font-medium truncate">
                                {upload.original_filename}
                              </p>
                              <p className="text-xs text-muted-foreground">
                                {formatDateTime(upload.created_at)}
                                {upload.uploaded_by?.name && ` · ${upload.uploaded_by.name}`}
                              </p>
                            </div>
                            <div className="flex gap-1 shrink-0">
                              <Button variant="ghost" size="sm" asChild>
                                <a href={`/waybills/import/${upload.id}`}>
                                  <Eye className="h-3.5 w-3.5" />
                                </a>
                              </Button>
                              {processing && (
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => handleCancel(upload.id)}
                                  className="text-destructive hover:text-destructive"
                                >
                                  <StopCircle className="h-3.5 w-3.5" />
                                </Button>
                              )}
                              {upload.status === 'failed' && (
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => handleRetry(upload.id)}
                                >
                                  <RefreshCw className="h-3.5 w-3.5" />
                                </Button>
                              )}
                            </div>
                          </div>

                          {/* Progress bar for processing uploads */}
                          {processing && (
                            <div className="space-y-1">
                              <Progress value={pct} className="h-1.5" />
                              <div className="flex justify-between text-xs text-muted-foreground">
                                <span>
                                  {(live?.processed_rows ?? upload.processed_rows).toLocaleString()}{' '}
                                  / {(live?.total_rows ?? upload.total_rows).toLocaleString()} rows
                                </span>
                                <span>{pct}%</span>
                              </div>
                            </div>
                          )}

                          {/* Stats row */}
                          {!processing &&
                            upload.status !== 'queued' &&
                            upload.status !== 'validating' && (
                              <div className="flex gap-4 text-xs text-muted-foreground flex-wrap">
                                {upload.total_rows > 0 && (
                                  <span>Total: {upload.total_rows.toLocaleString()}</span>
                                )}
                                {upload.inserted_rows > 0 && (
                                  <span className="text-success">
                                    Inserted: {upload.inserted_rows.toLocaleString()}
                                  </span>
                                )}
                                {upload.updated_rows > 0 && (
                                  <span className="text-info">
                                    Updated: {upload.updated_rows.toLocaleString()}
                                  </span>
                                )}
                                {upload.skipped_rows > 0 && (
                                  <span className="text-muted-foreground">
                                    Skipped: {upload.skipped_rows.toLocaleString()}
                                  </span>
                                )}
                                {upload.error_rows > 0 && (
                                  <span className="text-destructive">
                                    Errors: {upload.error_rows.toLocaleString()}
                                  </span>
                                )}
                              </div>
                            )}

                          {/* Error details */}
                          {upload.status === 'failed' && upload.errors && (
                            <div className="rounded-md bg-destructive/5 p-2 text-xs text-destructive">
                              {Array.isArray(upload.errors)
                                ? upload.errors.slice(0, 3).map((e, i) => (
                                    <div key={i}>
                                      {e.row ? `Row ${e.row}: ` : ''}
                                      {e.error}
                                    </div>
                                  ))
                                : upload.errors &&
                                    typeof upload.errors === 'object' &&
                                    'message' in upload.errors
                                  ? String((upload.errors as Record<string, unknown>).message)
                                  : 'Import failed.'}
                              {Array.isArray(upload.errors) && upload.errors.length > 3 && (
                                <div className="mt-1 text-muted-foreground">
                                  ...and {upload.errors.length - 3} more errors
                                </div>
                              )}
                            </div>
                          )}

                          {/* Completed with errors - download link */}
                          {upload.status === 'completed_with_errors' && upload.error_rows > 0 && (
                            <Button variant="ghost" size="sm" asChild>
                              <a href={`/waybills/import/${upload.id}/errors/download`}>
                                <RefreshCw className="h-3 w-3 mr-1" />
                                Download Error Report
                              </a>
                            </Button>
                          )}
                        </div>
                      );
                    })
                  )}
                </div>

                {/* Pagination */}
                {uploads.links && uploads.links.some((l) => l.url !== null) && (
                  <div className="flex items-center justify-center gap-2 mt-6">
                    {uploads.links.map((link, i) => (
                      <Button
                        key={i}
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        disabled={!link.url}
                        onClick={() => link.url && router.visit(link.url)}
                      >
                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                      </Button>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
