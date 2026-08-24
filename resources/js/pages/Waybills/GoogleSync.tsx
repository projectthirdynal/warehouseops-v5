import { Head, router } from '@inertiajs/react';
import { useState, useEffect, useCallback, useRef } from 'react';
import {
  FileSpreadsheet,
  RefreshCw,
  Plus,
  CheckCircle,
  XCircle,
  Clock,
  AlertCircle,
  Loader2,
  Trash2,
  Pencil,
  ExternalLink,
  Link2,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/utils';
import { toast } from 'sonner';

interface GoogleSheetSyncRecord {
  id: number;
  name: string;
  courier: string;
  sheet_url: string;
  sheet_gid: string;
  is_active: boolean;
  sync_interval_minutes: number;
  last_sync_status: 'pending' | 'processing' | 'completed' | 'completed_with_errors' | 'failed';
  last_sync_message: string | null;
  last_sync_rows: number;
  last_sync_inserted: number;
  last_sync_updated: number;
  last_sync_skipped: number;
  last_sync_errors: number;
  last_synced_at: string | null;
  created_by: { name: string } | null;
  created_at: string;
}

interface SyncStatus {
  id: number;
  last_sync_status: string;
  last_sync_message: string | null;
  last_sync_rows: number;
  last_sync_inserted: number;
  last_sync_updated: number;
  last_sync_skipped: number;
  last_sync_errors: number;
  last_synced_at: string | null;
}

interface Props {
  syncs: {
    data: GoogleSheetSyncRecord[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
  stats: {
    total_syncs: number;
    active_syncs: number;
    total_synced: number;
    recent_errors: number;
  };
}

const getCourierLabel = (courier: string) => {
  return courier === 'jnt' ? 'J&T Express' : courier === 'flash' ? 'Flash Express' : courier;
};

export default function GoogleSync({ syncs, stats }: Props) {
  const [showAddDialog, setShowAddDialog] = useState(false);
  const [editingSync, setEditingSync] = useState<GoogleSheetSyncRecord | null>(null);
  const [liveStatus, setLiveStatus] = useState<Record<number, SyncStatus>>({});
  const pollTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Form state
  const [formData, setFormData] = useState({
    name: '',
    courier: 'jnt',
    sheet_url: '',
    sheet_gid: '0',
    sync_interval_minutes: 15,
    is_active: true,
  });
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const processingIds = syncs.data
    .filter((s) => s.last_sync_status === 'processing')
    .map((s) => s.id);

  const pollStatus = useCallback(async (id: number) => {
    try {
      const res = await fetch(`/waybills/google-sync/${id}/status`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!res.ok) return;
      const data: SyncStatus = await res.json();
      setLiveStatus((prev) => ({ ...prev, [id]: data }));

      if (data.last_sync_status !== 'processing') {
        router.reload({ only: ['syncs', 'stats'] });
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

  const resetForm = () => {
    setFormData({
      name: '',
      courier: 'jnt',
      sheet_url: '',
      sheet_gid: '0',
      sync_interval_minutes: 15,
      is_active: true,
    });
    setFormError(null);
    setEditingSync(null);
  };

  const openAddDialog = () => {
    resetForm();
    setShowAddDialog(true);
  };

  const openEditDialog = (sync: GoogleSheetSyncRecord) => {
    setFormData({
      name: sync.name,
      courier: sync.courier,
      sheet_url: sync.sheet_url,
      sheet_gid: sync.sheet_gid,
      sync_interval_minutes: sync.sync_interval_minutes,
      is_active: sync.is_active,
    });
    setEditingSync(sync);
    setFormError(null);
    setShowAddDialog(true);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError(null);
    setSubmitting(true);

    const csrfToken =
      (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

    try {
      const url = editingSync ? `/waybills/google-sync/${editingSync.id}` : '/waybills/google-sync';
      const method = editingSync ? 'PATCH' : 'POST';

      const res = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
        body: JSON.stringify(formData),
      });

      const data = await res.json();

      if (!res.ok) {
        const msg =
          data?.errors?.sheet_url?.[0] ??
          data?.errors?.name?.[0] ??
          data?.errors?.courier?.[0] ??
          data?.message ??
          'Failed to save sync configuration.';
        throw new Error(msg);
      }

      toast.success(
        editingSync ? 'Sync configuration updated.' : (data.message ?? 'Sync configured.')
      );
      setShowAddDialog(false);
      resetForm();
      router.reload({ only: ['syncs', 'stats'] });
    } catch (err) {
      setFormError(err instanceof Error ? err.message : 'Failed to save.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleRefresh = async (sync: GoogleSheetSyncRecord) => {
    const csrfToken =
      (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

    try {
      const res = await fetch(`/waybills/google-sync/${sync.id}/refresh`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
      });

      const data = await res.json();

      if (!res.ok) {
        throw new Error(data?.error ?? 'Failed to start sync.');
      }

      toast.success(data.message ?? 'Sync started...');
      router.reload({ only: ['syncs', 'stats'] });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to start sync.');
    }
  };

  const handleDelete = async (sync: GoogleSheetSyncRecord) => {
    if (!confirm(`Remove "${sync.name}"? Waybills already imported will remain in the system.`)) {
      return;
    }

    const csrfToken =
      (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

    try {
      const res = await fetch(`/waybills/google-sync/${sync.id}`, {
        method: 'DELETE',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
      });

      if (!res.ok) {
        const data = await res.json();
        throw new Error(data?.message ?? 'Failed to delete.');
      }

      toast.success('Sync configuration removed.');
      router.reload({ only: ['syncs', 'stats'] });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to delete.');
    }
  };

  const handleToggleActive = async (sync: GoogleSheetSyncRecord, active: boolean) => {
    const csrfToken =
      (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

    try {
      const res = await fetch(`/waybills/google-sync/${sync.id}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
        body: JSON.stringify({ is_active: active }),
      });

      if (!res.ok) throw new Error('Failed to update.');

      toast.success(active ? 'Sync activated.' : 'Sync paused.');
      router.reload({ only: ['syncs', 'stats'] });
    } catch {
      toast.error('Failed to update status.');
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
      case 'failed':
        return (
          <Badge className="bg-destructive/10 text-destructive border-destructive/20">
            <XCircle className="w-3 h-3 mr-1" /> Failed
          </Badge>
        );
      default:
        return (
          <Badge className="bg-muted text-foreground border-border">
            <Clock className="w-3 h-3 mr-1" /> Pending
          </Badge>
        );
    }
  };

  return (
    <AppLayout>
      <Head title="Google Sheet Sync" />

      <div className="space-y-4">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">Google Sheet Sync</h1>
            <p className="text-muted-foreground">
              Link a Google Sheet to automatically sync waybills. Data is saved to the system and
              stays available even when the sheet link changes.
            </p>
          </div>
          <Button onClick={openAddDialog}>
            <Plus className="mr-1.5 h-4 w-4" />
            Add Sheet
          </Button>
        </div>

        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Total Syncs</CardTitle>
              <Link2 className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{stats.total_syncs}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Active</CardTitle>
              <CheckCircle className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{stats.active_syncs}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Waybills Synced</CardTitle>
              <FileSpreadsheet className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">
                {stats.total_synced.toLocaleString()}
              </div>
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

        {/* Sync configurations list */}
        <div className="space-y-4">
          {syncs.data.length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                <FileSpreadsheet className="mx-auto h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 font-medium">No Google Sheet syncs configured</p>
                <p className="text-sm text-muted-foreground mt-1">
                  Add a Google Sheet link to start syncing waybills automatically.
                </p>
                <Button className="mt-4" onClick={openAddDialog}>
                  <Plus className="mr-1.5 h-4 w-4" />
                  Add Sheet
                </Button>
              </CardContent>
            </Card>
          ) : (
            syncs.data.map((sync) => {
              const live = liveStatus[sync.id];
              const status = live?.last_sync_status ?? sync.last_sync_status;
              const isProcessing = status === 'processing';

              return (
                <Card key={sync.id}>
                  <CardHeader>
                    <div className="flex items-start justify-between gap-4">
                      <div className="space-y-1 min-w-0 flex-1">
                        <div className="flex items-center gap-2 flex-wrap">
                          <CardTitle className="text-base">{sync.name}</CardTitle>
                          {getStatusBadge(status)}
                          <Badge variant="outline">{getCourierLabel(sync.courier)}</Badge>
                          {!sync.is_active && (
                            <Badge className="bg-muted text-muted-foreground">Paused</Badge>
                          )}
                        </div>
                        <CardDescription className="flex items-center gap-1.5 min-w-0">
                          <Link2 className="h-3.5 w-3.5 shrink-0" />
                          <a
                            href={sync.sheet_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="truncate hover:text-primary hover:underline"
                          >
                            {sync.sheet_url}
                          </a>
                          <ExternalLink className="h-3 w-3 shrink-0 text-muted-foreground" />
                        </CardDescription>
                      </div>
                      <div className="flex items-center gap-2 shrink-0">
                        <Button
                          size="sm"
                          onClick={() => handleRefresh(sync)}
                          disabled={isProcessing}
                        >
                          {isProcessing ? (
                            <>
                              <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                              Syncing...
                            </>
                          ) : (
                            <>
                              <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                              Refresh
                            </>
                          )}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => openEditDialog(sync)}
                          disabled={isProcessing}
                        >
                          <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => handleDelete(sync)}
                          disabled={isProcessing}
                        >
                          <Trash2 className="h-3.5 w-3.5 text-destructive" />
                        </Button>
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent>
                    <div className="grid gap-4 md:grid-cols-3">
                      {/* Sync stats */}
                      <div className="space-y-2">
                        <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                          Last Sync Result
                        </p>
                        {(live ?? sync).last_sync_message ? (
                          <p className="text-sm">{(live ?? sync).last_sync_message}</p>
                        ) : (
                          <p className="text-sm text-muted-foreground">No sync yet.</p>
                        )}
                        {(live ?? sync).last_synced_at && (
                          <p className="text-xs text-muted-foreground">
                            {formatDateTime((live ?? sync).last_synced_at as string)}
                          </p>
                        )}
                      </div>

                      {/* Row breakdown */}
                      <div className="space-y-2">
                        <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                          Rows
                        </p>
                        <div className="flex flex-wrap gap-3 text-sm">
                          <span className="text-muted-foreground">
                            Total:{' '}
                            <span className="font-medium text-foreground">
                              {(live ?? sync).last_sync_rows}
                            </span>
                          </span>
                          <span className="text-success">
                            New:{' '}
                            <span className="font-medium">{(live ?? sync).last_sync_inserted}</span>
                          </span>
                          <span className="text-info">
                            Updated:{' '}
                            <span className="font-medium">{(live ?? sync).last_sync_updated}</span>
                          </span>
                          <span className="text-muted-foreground">
                            Skipped:{' '}
                            <span className="font-medium">{(live ?? sync).last_sync_skipped}</span>
                          </span>
                          {(live ?? sync).last_sync_errors > 0 && (
                            <span className="text-destructive">
                              Errors:{' '}
                              <span className="font-medium">{(live ?? sync).last_sync_errors}</span>
                            </span>
                          )}
                        </div>
                      </div>

                      {/* Auto-sync settings */}
                      <div className="space-y-2">
                        <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                          Auto-Sync
                        </p>
                        <div className="flex items-center gap-2">
                          <Switch
                            checked={sync.is_active}
                            onCheckedChange={(checked) => handleToggleActive(sync, checked)}
                            disabled={isProcessing}
                          />
                          <span className="text-sm">
                            {sync.is_active ? `Every ${sync.sync_interval_minutes} min` : 'Paused'}
                          </span>
                        </div>
                        {sync.created_by && (
                          <p className="text-xs text-muted-foreground">
                            Created by {sync.created_by.name}
                          </p>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })
          )}
        </div>

        {/* Pagination */}
        {syncs.links.length > 3 && (
          <div className="flex items-center justify-center gap-2">
            {syncs.links.map((link, i) => (
              <Button
                key={i}
                variant={link.active ? 'default' : 'outline'}
                size="sm"
                disabled={!link.url}
                onClick={() => {
                  if (link.url) router.visit(link.url, { only: ['syncs', 'stats'] });
                }}
                dangerouslySetInnerHTML={{ __html: link.label }}
              />
            ))}
          </div>
        )}
      </div>

      {/* Add/Edit Dialog */}
      <Dialog open={showAddDialog} onOpenChange={setShowAddDialog}>
        <DialogContent className="sm:max-w-[520px]">
          <DialogHeader>
            <DialogTitle>
              {editingSync ? 'Edit Sync Configuration' : 'Add Google Sheet Sync'}
            </DialogTitle>
            <DialogDescription>
              Paste the Google Sheet sharing link. The sheet must be shared as "Anyone with the link
              can view" or published to the web.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="name">Name</Label>
              <Input
                id="name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="e.g. J&T Monthly Waybills"
                required
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="courier">Courier Provider</Label>
              <Select
                value={formData.courier}
                onValueChange={(v) => setFormData({ ...formData, courier: v })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select courier" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="jnt">J&T Express</SelectItem>
                  <SelectItem value="flash">Flash Express</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="sheet_url">Google Sheet Link</Label>
              <Input
                id="sheet_url"
                type="url"
                value={formData.sheet_url}
                onChange={(e) => setFormData({ ...formData, sheet_url: e.target.value })}
                placeholder="https://docs.google.com/spreadsheets/d/..."
                required
              />
              <p className="text-xs text-muted-foreground">
                Paste the full sharing URL. The system will automatically convert it to a CSV export
                link.
              </p>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="sheet_gid">Sheet GID (optional)</Label>
                <Input
                  id="sheet_gid"
                  value={formData.sheet_gid}
                  onChange={(e) => setFormData({ ...formData, sheet_gid: e.target.value })}
                  placeholder="0"
                />
                <p className="text-xs text-muted-foreground">
                  The sheet/tab ID. Found in the URL after <code>#gid=</code>.
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="sync_interval_minutes">Auto-Sync Interval (min)</Label>
                <Input
                  id="sync_interval_minutes"
                  type="number"
                  min={5}
                  max={1440}
                  value={formData.sync_interval_minutes}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      sync_interval_minutes: parseInt(e.target.value) || 15,
                    })
                  }
                />
              </div>
            </div>

            <div className="flex items-center gap-2">
              <Switch
                id="is_active"
                checked={formData.is_active}
                onCheckedChange={(checked) => setFormData({ ...formData, is_active: checked })}
              />
              <Label htmlFor="is_active">Enable automatic sync</Label>
            </div>

            {formError && (
              <div className="flex items-start gap-2 p-3 bg-destructive/5 border border-destructive/20 rounded-md">
                <AlertCircle className="h-4 w-4 text-destructive mt-0.5 shrink-0" />
                <p className="text-sm text-destructive">{formError}</p>
              </div>
            )}

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setShowAddDialog(false)}
                disabled={submitting}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? (
                  <>
                    <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    Saving...
                  </>
                ) : editingSync ? (
                  'Update'
                ) : (
                  'Add Sync'
                )}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
