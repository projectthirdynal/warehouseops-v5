import { Head, router } from '@inertiajs/react';
import { useState, useRef, useCallback } from 'react';
import { HelpCircle, Search, CheckCircle2, XCircle, Clock, ChevronLeft, ChevronRight } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface WaybillSuggestion {
  id: number;
  waybill_number: string;
  receiver_name: string;
  city: string | null;
  status: string;
}

interface UnknownScan {
  id: number;
  waybill_no: string;
  resolution_status: 'PENDING' | 'RESOLVED' | 'DISMISSED';
  notes: string | null;
  scanned_at: string;
  resolved_at: string | null;
  scan_session_id: string;
  scanned_by: { id: number; name: string } | null;
  resolved_by: { id: number; name: string } | null;
  resolved_to_waybill: WaybillSuggestion | null;
}

interface Stats {
  pending: number;
  resolved: number;
  dismissed: number;
}

interface Props {
  unknowns: PaginatedResponse<UnknownScan>;
  stats: Stats;
  filters: { status?: string; search?: string };
}

// ─── Status config ────────────────────────────────────────────────────────────

const STATUS_META = {
  PENDING:   { label: 'Pending',   badgeCls: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200', icon: <Clock className="h-3.5 w-3.5 text-orange-500" /> },
  RESOLVED:  { label: 'Resolved',  badgeCls: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',   icon: <CheckCircle2 className="h-3.5 w-3.5 text-green-600" /> },
  DISMISSED: { label: 'Dismissed', badgeCls: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',       icon: <XCircle className="h-3.5 w-3.5 text-gray-400" /> },
};

// ─── Component ────────────────────────────────────────────────────────────────

export default function UnknownWaybills({ unknowns, stats, filters }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [statusFilter, setStatusFilter] = useState(filters.status ?? '');

  // Match dialog
  const [matchTarget, setMatchTarget] = useState<UnknownScan | null>(null);
  const [matchQuery, setMatchQuery] = useState('');
  const [suggestions, setSuggestions] = useState<WaybillSuggestion[]>([]);
  const [selectedWaybill, setSelectedWaybill] = useState<WaybillSuggestion | null>(null);
  const [matchNotes, setMatchNotes] = useState('');
  const [matchLoading, setMatchLoading] = useState(false);
  const suggestTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Dismiss dialog
  const [dismissTarget, setDismissTarget] = useState<UnknownScan | null>(null);
  const [dismissNotes, setDismissNotes] = useState('');
  const [dismissSubmitting, setDismissSubmitting] = useState(false);

  const applyFilters = useCallback((overrides: Partial<{ status: string; search: string }> = {}) => {
    const params: Record<string, string> = {};
    const s = overrides.search !== undefined ? overrides.search : search;
    const st = overrides.status !== undefined ? overrides.status : statusFilter;
    if (s) params.search = s;
    if (st) params.status = st;
    router.get('/waybills/unknown', params, { preserveState: true, replace: true });
  }, [search, statusFilter]);

  // Fetch suggestions with debounce
  const fetchSuggestions = useCallback((q: string) => {
    if (suggestTimer.current) clearTimeout(suggestTimer.current);
    setSuggestions([]);
    setSelectedWaybill(null);
    if (q.length < 3) return;
    suggestTimer.current = setTimeout(async () => {
      try {
        const res = await fetch(`/waybills/unknown/suggest?q=${encodeURIComponent(q)}`, {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        setSuggestions(data.waybills ?? []);
      } catch { /* network error */ }
    }, 280);
  }, []);

  const openMatch = (scan: UnknownScan) => {
    setMatchTarget(scan);
    setMatchQuery(scan.waybill_no);
    fetchSuggestions(scan.waybill_no);
    setMatchNotes('');
    setSelectedWaybill(null);
  };

  const submitMatch = () => {
    if (!matchTarget || !selectedWaybill) return;
    setMatchLoading(true);
    router.post(
      `/waybills/unknown/${matchTarget.id}/match`,
      { waybill_id: selectedWaybill.id, notes: matchNotes },
      {
        onFinish: () => {
          setMatchLoading(false);
          setMatchTarget(null);
        },
      }
    );
  };

  const openDismiss = (scan: UnknownScan) => {
    setDismissTarget(scan);
    setDismissNotes('');
  };

  const submitDismiss = () => {
    if (!dismissTarget || !dismissNotes.trim()) return;
    setDismissSubmitting(true);
    router.post(
      `/waybills/unknown/${dismissTarget.id}/dismiss`,
      { notes: dismissNotes },
      {
        onFinish: () => {
          setDismissSubmitting(false);
          setDismissTarget(null);
        },
      }
    );
  };

  const goToPage = (page: number) => {
    const params: Record<string, string | number> = { page };
    if (search) params.search = search;
    if (statusFilter) params.status = statusFilter;
    router.get('/waybills/unknown', params, { preserveState: true });
  };

  return (
    <AppLayout>
      <Head title="Unknown Waybills" />

      <div className="space-y-5">
        {/* Header */}
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Unknown Waybills</h1>
          <p className="text-muted-foreground text-sm mt-0.5">
            Waybills scanned but not found in the system. Match to an existing record or dismiss.
          </p>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-3 gap-3">
          {[
            { label: 'Pending Review', value: stats.pending, cls: 'text-orange-500' },
            { label: 'Resolved', value: stats.resolved, cls: 'text-green-600' },
            { label: 'Dismissed', value: stats.dismissed, cls: 'text-muted-foreground' },
          ].map((s) => (
            <Card key={s.label}>
              <CardContent className="flex items-center justify-between px-4 py-3">
                <span className="text-sm text-muted-foreground">{s.label}</span>
                <span className={cn('text-2xl font-bold tabular-nums', s.cls)}>{s.value}</span>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="flex flex-wrap items-center gap-3 pt-4 pb-4">
            <div className="relative flex-1 min-w-[200px]">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search waybill number…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && applyFilters({ search })}
                className="pl-9"
              />
            </div>
            <div className="flex rounded-lg border overflow-hidden text-sm">
              {[
                { value: '', label: 'All' },
                { value: 'PENDING', label: 'Pending' },
                { value: 'RESOLVED', label: 'Resolved' },
                { value: 'DISMISSED', label: 'Dismissed' },
              ].map((opt) => (
                <button
                  key={opt.value}
                  onClick={() => {
                    setStatusFilter(opt.value);
                    applyFilters({ status: opt.value });
                  }}
                  className={cn(
                    'px-3 py-1.5 transition-colors',
                    statusFilter === opt.value
                      ? 'bg-primary text-primary-foreground font-medium'
                      : 'bg-background text-muted-foreground hover:bg-muted'
                  )}
                >
                  {opt.label}
                </button>
              ))}
            </div>
            <Button variant="outline" onClick={() => applyFilters()}>Apply</Button>
          </CardContent>
        </Card>

        {/* Table */}
        <Card>
          <CardContent className="p-0">
            {unknowns.data.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-14 text-muted-foreground">
                <HelpCircle className="h-10 w-10 opacity-25 mb-3" />
                <p>No unknown waybills found.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b bg-muted/40">
                      <th className="px-4 py-3 text-left font-medium text-muted-foreground">Waybill #</th>
                      <th className="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                      <th className="px-4 py-3 text-left font-medium text-muted-foreground">Scanned By</th>
                      <th className="px-4 py-3 text-left font-medium text-muted-foreground">Scanned At</th>
                      <th className="px-4 py-3 text-left font-medium text-muted-foreground">Resolved To</th>
                      <th className="px-4 py-3 text-left font-medium text-muted-foreground">Notes</th>
                      <th className="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {unknowns.data.map((scan) => {
                      const meta = STATUS_META[scan.resolution_status] ?? STATUS_META.PENDING;
                      return (
                        <tr key={scan.id} className="border-b hover:bg-muted/20 transition-colors">
                          <td className="px-4 py-3 font-mono font-medium">{scan.waybill_no}</td>
                          <td className="px-4 py-3">
                            <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium', meta.badgeCls)}>
                              {meta.icon}
                              {meta.label}
                            </span>
                          </td>
                          <td className="px-4 py-3 text-muted-foreground">{scan.scanned_by?.name ?? '—'}</td>
                          <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">
                            {new Date(scan.scanned_at).toLocaleString('en-PH', {
                              month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
                            })}
                          </td>
                          <td className="px-4 py-3">
                            {scan.resolved_to_waybill ? (
                              <span className="font-mono text-xs text-green-700 dark:text-green-400">
                                {scan.resolved_to_waybill.waybill_number}
                              </span>
                            ) : '—'}
                          </td>
                          <td className="px-4 py-3 text-muted-foreground max-w-[180px] truncate">
                            {scan.notes ?? '—'}
                          </td>
                          <td className="px-4 py-3 text-right">
                            {scan.resolution_status === 'PENDING' && (
                              <div className="flex justify-end gap-2">
                                <Button
                                  size="sm"
                                  variant="outline"
                                  className="h-7 text-xs"
                                  onClick={() => openMatch(scan)}
                                >
                                  <CheckCircle2 className="mr-1 h-3.5 w-3.5" />
                                  Match
                                </Button>
                                <Button
                                  size="sm"
                                  variant="ghost"
                                  className="h-7 text-xs text-muted-foreground"
                                  onClick={() => openDismiss(scan)}
                                >
                                  <XCircle className="mr-1 h-3.5 w-3.5" />
                                  Dismiss
                                </Button>
                              </div>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            {/* Pagination */}
            {unknowns.last_page > 1 && (
              <div className="flex items-center justify-between px-4 py-3 border-t">
                <p className="text-sm text-muted-foreground">
                  Showing {unknowns.from}–{unknowns.to} of {unknowns.total}
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={unknowns.current_page <= 1}
                    onClick={() => goToPage(unknowns.current_page - 1)}
                  >
                    <ChevronLeft className="h-4 w-4" />
                  </Button>
                  <span className="flex items-center text-sm px-2">
                    {unknowns.current_page} / {unknowns.last_page}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={unknowns.current_page >= unknowns.last_page}
                    onClick={() => goToPage(unknowns.current_page + 1)}
                  >
                    <ChevronRight className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Match Dialog */}
      <Dialog open={!!matchTarget} onOpenChange={(open) => !open && setMatchTarget(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Match Unknown Waybill</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div>
              <p className="text-sm text-muted-foreground mb-1">
                Scanned: <span className="font-mono font-medium text-foreground">{matchTarget?.waybill_no}</span>
              </p>
            </div>
            <div className="space-y-1.5">
              <Label>Search existing waybill</Label>
              <Input
                placeholder="Type waybill number (min 3 chars)…"
                value={matchQuery}
                onChange={(e) => {
                  setMatchQuery(e.target.value);
                  fetchSuggestions(e.target.value);
                }}
              />
            </div>
            {suggestions.length > 0 && (
              <div className="rounded-lg border divide-y max-h-[200px] overflow-y-auto">
                {suggestions.map((w) => (
                  <button
                    key={w.id}
                    onClick={() => { setSelectedWaybill(w); setSuggestions([]); setMatchQuery(w.waybill_number); }}
                    className={cn(
                      'w-full flex items-start gap-3 px-3 py-2.5 text-left hover:bg-muted/50 transition-colors',
                      selectedWaybill?.id === w.id && 'bg-primary/5'
                    )}
                  >
                    <div className="flex-1 min-w-0">
                      <p className="font-mono text-sm font-medium">{w.waybill_number}</p>
                      <p className="text-xs text-muted-foreground truncate">
                        {w.receiver_name}{w.city ? ` · ${w.city}` : ''} · {w.status}
                      </p>
                    </div>
                    {selectedWaybill?.id === w.id && <CheckCircle2 className="h-4 w-4 text-primary shrink-0 mt-0.5" />}
                  </button>
                ))}
              </div>
            )}
            {selectedWaybill && (
              <div className="rounded-lg border border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950 px-3 py-2 text-sm">
                <span className="text-green-700 dark:text-green-300">Matched to: </span>
                <span className="font-mono font-medium">{selectedWaybill.waybill_number}</span>
                <span className="text-muted-foreground ml-1">— {selectedWaybill.receiver_name}</span>
              </div>
            )}
            <div className="space-y-1.5">
              <Label>Notes <span className="text-muted-foreground font-normal">(optional)</span></Label>
              <Textarea
                placeholder="Why this waybill matches…"
                value={matchNotes}
                onChange={(e) => setMatchNotes(e.target.value)}
                rows={2}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setMatchTarget(null)}>Cancel</Button>
            <Button
              onClick={submitMatch}
              disabled={!selectedWaybill || matchLoading}
            >
              {matchLoading ? 'Matching…' : 'Confirm Match'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Dismiss Dialog */}
      <Dialog open={!!dismissTarget} onOpenChange={(open) => !open && setDismissTarget(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Dismiss Unknown Waybill</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <p className="text-sm text-muted-foreground">
              Scanned: <span className="font-mono font-medium text-foreground">{dismissTarget?.waybill_no}</span>
            </p>
            <div className="space-y-1.5">
              <Label>Reason for dismissal <span className="text-destructive">*</span></Label>
              <Textarea
                placeholder="e.g. Test scan, duplicate number, mislabeled parcel…"
                value={dismissNotes}
                onChange={(e) => setDismissNotes(e.target.value)}
                rows={3}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDismissTarget(null)}>Cancel</Button>
            <Button
              variant="destructive"
              onClick={submitDismiss}
              disabled={!dismissNotes.trim() || dismissSubmitting}
            >
              {dismissSubmitting ? 'Dismissing…' : 'Dismiss'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
