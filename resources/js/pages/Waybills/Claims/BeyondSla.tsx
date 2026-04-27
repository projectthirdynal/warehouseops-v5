import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Card } from '@/components/ui/card';
import {
  AlertTriangle,
  PackageX,
  Download,
  ChevronDown,
  ScanLine,
  CheckCircle,
  XCircle,
  AlertCircle,
  Loader2,
} from 'lucide-react';
import { formatDate } from '@/lib/utils';
import { DateRangePicker, usePersistedDateRange } from '@/components/DateRangePicker';
import type { PaginatedResponse, Waybill } from '@/types';

interface Props {
  waybills: PaginatedResponse<Waybill & { claims?: { id: number }[] }>;
  beyond_sla_count: number;
  filters: { search?: string; from?: string; to?: string };
}

type ScanStatus = 'ok' | 'beyond_sla' | 'already_processed' | 'wrong_status' | 'unknown' | 'duplicate' | 'scanning';

interface ScanEntry {
  id: string;
  waybill_number: string;
  status: ScanStatus;
  message: string;
  ts: number;
}

function uuid(): string {
  return crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).slice(2);
}

function getCsrf(): string {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
}

function playBeep(type: 'success' | 'error' | 'warn'): void {
  try {
    const ctx = new AudioContext();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);
    const configs = {
      success: { freq: 880, dur: 0.12, vol: 0.3 },
      warn:    { freq: 520, dur: 0.2,  vol: 0.35 },
      error:   { freq: 220, dur: 0.35, vol: 0.45 },
    };
    const c = configs[type];
    osc.frequency.value = c.freq;
    gain.gain.setValueAtTime(c.vol, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + c.dur);
    osc.start();
    osc.stop(ctx.currentTime + c.dur);
    osc.onended = () => ctx.close();
  } catch {
    // AudioContext unavailable — silent
  }
}

const STATUS_META: Record<ScanStatus, {
  label: string;
  icon: React.ReactNode;
  rowCls: string;
  beep: 'success' | 'warn' | 'error' | null;
}> = {
  ok:                { label: 'Received',        icon: <CheckCircle className="h-4 w-4 text-green-500" />,  rowCls: 'bg-green-50',  beep: 'success' },
  beyond_sla:        { label: 'Received (late)',  icon: <AlertCircle className="h-4 w-4 text-orange-500" />, rowCls: 'bg-orange-50', beep: 'warn' },
  already_processed: { label: 'Already received', icon: <CheckCircle className="h-4 w-4 text-blue-400" />,  rowCls: 'bg-blue-50',   beep: null },
  wrong_status:      { label: 'Wrong status',     icon: <XCircle className="h-4 w-4 text-red-500" />,       rowCls: 'bg-red-50',    beep: 'error' },
  unknown:           { label: 'Not found',        icon: <XCircle className="h-4 w-4 text-red-500" />,       rowCls: 'bg-red-50',    beep: 'error' },
  duplicate:         { label: 'Duplicate scan',   icon: <AlertCircle className="h-4 w-4 text-gray-400" />,  rowCls: 'bg-gray-50',   beep: null },
  scanning:          { label: 'Scanning…',        icon: <Loader2 className="h-4 w-4 animate-spin" />,       rowCls: 'bg-white',     beep: null },
};

function daysOverdue(returnedAt: string): number {
  const returned = new Date(
    returnedAt.includes('T') ? returnedAt : returnedAt.replace(' ', 'T') + '+08:00'
  );
  return Math.max(0, Math.floor((Date.now() - returned.getTime()) / (1000 * 60 * 60 * 24)));
}

function slaDeadlineLabel(returnedAt: string): string {
  const d = new Date(
    returnedAt.includes('T') ? returnedAt : returnedAt.replace(' ', 'T') + '+08:00'
  );
  d.setDate(d.getDate() + 1);
  return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

export default function BeyondSla({ waybills, beyond_sla_count, filters }: Props) {
  const [sessionId]                   = useState(() => uuid());
  const [inputVal, setInputVal]       = useState('');
  const [scanFeed, setScanFeed]       = useState<ScanEntry[]>([]);
  const [scannerOpen, setScannerOpen] = useState(false);
  const [search, setSearch]           = useState(filters.search ?? '');
  const inputRef                      = useRef<HTMLInputElement>(null);
  const reloadTimer                   = useRef<ReturnType<typeof setTimeout> | null>(null);
  const dateRange = usePersistedDateRange('beyond-sla-range', filters.from, filters.to);

  function applyFilters(overrides: Record<string, string>) {
    router.get('/waybills/claims/beyond-sla', { ...filters, ...overrides }, { preserveState: true, replace: true });
  }

  function handleSearch(e: React.FormEvent) {
    e.preventDefault();
    applyFilters({ search, page: '1' });
  }

  function exportUrl(format: string) {
    const params = new URLSearchParams({ format });
    if (filters.from)   params.set('from', filters.from);
    if (filters.to)     params.set('to', filters.to);
    if (filters.search) params.set('search', filters.search);
    return `/waybills/beyond-sla/export?${params.toString()}`;
  }

  // Debounce list reload — batch scans only trigger one refresh
  function scheduleReload() {
    if (reloadTimer.current) clearTimeout(reloadTimer.current);
    reloadTimer.current = setTimeout(() => {
      router.reload({ only: ['waybills', 'beyond_sla_count'] });
    }, 1500);
  }

  const refocus = useCallback(() => {
    setTimeout(() => inputRef.current?.focus(), 50);
  }, []);

  useEffect(() => {
    if (!scannerOpen) return;
    document.addEventListener('click', refocus);
    inputRef.current?.focus();
    return () => document.removeEventListener('click', refocus);
  }, [scannerOpen, refocus]);

  async function submitScan(raw: string) {
    const number = raw.trim().toUpperCase();
    if (!number) return;
    setInputVal('');

    const tempId = uuid();
    const pendingEntry: ScanEntry = { id: tempId, waybill_number: number, status: 'scanning', message: 'Scanning…', ts: Date.now() };
    setScanFeed(f => [pendingEntry, ...f].slice(0, 50));

    try {
      const res = await fetch('/waybills/scan', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': getCsrf(),
          'Accept': 'application/json',
        },
        body: JSON.stringify({ waybill_number: number, session_id: sessionId, mode: 'receive_return' }),
      });

      const data: { status?: string; message?: string } = await res.json();
      const status = (data.status ?? 'unknown') as ScanStatus;
      const meta = STATUS_META[status] ?? STATUS_META.unknown;

      if (meta.beep) playBeep(meta.beep);

      setScanFeed(f =>
        f.map((e): ScanEntry => e.id === tempId ? { ...e, status, message: data.message ?? meta.label } : e)
      );

      if (status === 'ok' || status === 'beyond_sla') scheduleReload();
    } catch {
      playBeep('error');
      setScanFeed(f =>
        f.map(e => e.id === tempId ? { ...e, status: 'unknown', message: 'Network error — check connection.' } : e)
      );
    }

    refocus();
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Enter') {
      e.preventDefault();
      submitScan(inputVal);
    }
  }

  return (
    <AppLayout>
      <Head title="Beyond SLA" />

      <div className="space-y-6 p-6">

        {/* Header */}
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex-1 min-w-0">
            <h1 className="text-2xl font-bold">Beyond SLA</h1>
            <p className="text-sm text-muted-foreground">
              Returned parcels not received at warehouse by the next calendar day (midnight)
            </p>
          </div>
          <DateRangePicker
            value={dateRange}
            storageKey="beyond-sla-range"
            onChange={(range) => applyFilters({ from: range.from, to: range.to, page: '1' })}
          />
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline">
                <Download className="mr-2 h-4 w-4" />
                Export
                <ChevronDown className="ml-1 h-3.5 w-3.5 text-muted-foreground" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={() => { window.location.href = exportUrl('xlsx'); }}>
                Excel (.xlsx)
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => { window.location.href = exportUrl('csv'); }}>
                CSV
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => { window.location.href = exportUrl('pdf'); }}>
                PDF
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <Button
            variant={scannerOpen ? 'default' : 'outline'}
            onClick={() => setScannerOpen(v => !v)}
          >
            <ScanLine className="mr-2 h-4 w-4" />
            {scannerOpen ? 'Hide Scanner' : 'Scan Received Returns'}
          </Button>
        </div>

        {/* Sub-nav */}
        <div className="flex gap-2 border-b pb-2">
          <Link href="/waybills/claims">
            <Button variant="ghost" size="sm">All Claims</Button>
          </Link>
          <Link href="/waybills/claims/approved">
            <Button variant="ghost" size="sm">Approved</Button>
          </Link>
          <Link href="/waybills/claims/beyond-sla">
            <Button variant="ghost" size="sm" className="font-medium border-b-2 border-primary rounded-none">
              Beyond SLA
            </Button>
          </Link>
        </div>

        {/* Alert */}
        {beyond_sla_count > 0 && (
          <div className="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
            <AlertTriangle className="h-5 w-5 shrink-0 text-red-500" />
            <p className="text-sm font-medium text-red-800">
              {beyond_sla_count} parcel{beyond_sla_count !== 1 ? 's' : ''} beyond SLA — J&T is obligated to compensate for these.
            </p>
          </div>
        )}

        {/* Continuous scanner panel */}
        {scannerOpen && (
          <Card className="p-4 space-y-3">
            <div className="flex items-center gap-2">
              <ScanLine className="h-4 w-4 text-muted-foreground" />
              <span className="text-sm font-medium">Scan Received Returns</span>
              <span className="text-xs text-muted-foreground">— scan barcode or type waybill number, press Enter</span>
            </div>

            <Input
              ref={inputRef}
              value={inputVal}
              onChange={e => setInputVal(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="Waiting for scan…"
              className="font-mono text-base max-w-sm"
              autoComplete="off"
              autoFocus
            />

            {scanFeed.length > 0 && (
              <div className="rounded-md border overflow-hidden max-h-64 overflow-y-auto">
                <table className="w-full text-sm">
                  <thead className="bg-muted/50 sticky top-0">
                    <tr>
                      <th className="px-3 py-2 text-left font-medium">Waybill #</th>
                      <th className="px-3 py-2 text-left font-medium">Result</th>
                      <th className="px-3 py-2 text-left font-medium">Details</th>
                      <th className="px-3 py-2 text-left font-medium text-muted-foreground">Time</th>
                    </tr>
                  </thead>
                  <tbody>
                    {scanFeed.map(entry => {
                      const meta = STATUS_META[entry.status] ?? STATUS_META.unknown;
                      return (
                        <tr key={entry.id} className={`border-t ${meta.rowCls}`}>
                          <td className="px-3 py-1.5 font-mono font-medium">{entry.waybill_number}</td>
                          <td className="px-3 py-1.5">
                            <span className="inline-flex items-center gap-1.5">
                              {meta.icon}
                              {meta.label}
                            </span>
                          </td>
                          <td className="px-3 py-1.5 text-muted-foreground">{entry.message}</td>
                          <td className="px-3 py-1.5 text-xs text-muted-foreground whitespace-nowrap">
                            {new Date(entry.ts).toLocaleTimeString('en-PH', {
                              hour: '2-digit', minute: '2-digit', second: '2-digit',
                            })}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            <p className="text-xs text-muted-foreground">
              Scanned items are removed from the Beyond SLA list below automatically.
            </p>
          </Card>
        )}

        {/* Search */}
        <form onSubmit={handleSearch} className="flex gap-2">
          <Input
            placeholder="Waybill # or receiver name..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-60"
          />
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {/* Table */}
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Waybill #</TableHead>
                <TableHead>Receiver</TableHead>
                <TableHead>City</TableHead>
                <TableHead className="text-right">COD Amount</TableHead>
                <TableHead>Returned Date</TableHead>
                <TableHead>SLA Deadline</TableHead>
                <TableHead>Days Overdue</TableHead>
                <TableHead>Existing Claims</TableHead>
                <TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {waybills.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={9} className="py-12 text-center text-muted-foreground">
                    <PackageX className="mx-auto mb-2 h-8 w-8 opacity-30" />
                    No parcels beyond SLA. All returned parcels have been received.
                  </TableCell>
                </TableRow>
              ) : (
                waybills.data.map((waybill) => {
                  const overdue = waybill.returned_at ? daysOverdue(waybill.returned_at as string) : 0;
                  const deadline = waybill.returned_at ? slaDeadlineLabel(waybill.returned_at as string) : '—';

                  return (
                    <TableRow key={waybill.id}>
                      <TableCell>
                        <Link href={`/waybills/${waybill.id}`} className="font-mono text-sm font-medium hover:underline">
                          {waybill.waybill_number}
                        </Link>
                      </TableCell>
                      <TableCell className="text-sm">{waybill.receiver_name}</TableCell>
                      <TableCell className="text-sm">{waybill.city}</TableCell>
                      <TableCell className="text-right font-medium">
                        ₱{Number(waybill.cod_amount ?? waybill.amount ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                      </TableCell>
                      <TableCell className="text-sm">
                        {waybill.returned_at ? formatDate(waybill.returned_at as string) : '—'}
                      </TableCell>
                      <TableCell className="text-sm font-medium text-orange-700">{deadline}</TableCell>
                      <TableCell>
                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                          overdue >= 3 ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700'
                        }`}>
                          {overdue}d overdue
                        </span>
                      </TableCell>
                      <TableCell className="text-sm">
                        {(waybill.claims?.length ?? 0) > 0 ? (
                          <span className="text-muted-foreground">{waybill.claims?.length} claim(s)</span>
                        ) : (
                          <span className="text-muted-foreground/50">None</span>
                        )}
                      </TableCell>
                      <TableCell>
                        <Link href={`/waybills/claims/create?waybill_id=${waybill.id}&type=BEYOND_SLA`}>
                          <Button size="sm" variant="outline">File Claim</Button>
                        </Link>
                      </TableCell>
                    </TableRow>
                  );
                })
              )}
            </TableBody>
          </Table>
        </Card>

        {/* Pagination */}
        {waybills.last_page > 1 && (
          <Pagination
            current={waybills.current_page}
            last={waybills.last_page}
            onJump={(p) => applyFilters({ page: String(p) })}
          />
        )}

      </div>
    </AppLayout>
  );
}

function Pagination({ current, last, onJump }: { current: number; last: number; onJump: (p: number) => void }) {
  // Show: first, current ± 2, last, with ellipses for gaps
  const window = 2;
  const pages = new Set<number>();
  pages.add(1);
  pages.add(last);
  for (let i = current - window; i <= current + window; i++) {
    if (i >= 1 && i <= last) pages.add(i);
  }
  const sorted = Array.from(pages).sort((a, b) => a - b);

  const items: (number | 'gap')[] = [];
  let prev = 0;
  for (const p of sorted) {
    if (prev && p - prev > 1) items.push('gap');
    items.push(p);
    prev = p;
  }

  return (
    <div className="flex flex-wrap items-center justify-center gap-1">
      <Button size="sm" variant="outline" disabled={current === 1} onClick={() => onJump(current - 1)}>
        ‹ Prev
      </Button>
      {items.map((p, idx) =>
        p === 'gap' ? (
          <span key={`g${idx}`} className="px-2 text-sm text-muted-foreground">…</span>
        ) : (
          <Button
            key={p}
            size="sm"
            variant={p === current ? 'default' : 'outline'}
            onClick={() => onJump(p)}
          >
            {p}
          </Button>
        )
      )}
      <Button size="sm" variant="outline" disabled={current === last} onClick={() => onJump(current + 1)}>
        Next ›
      </Button>
      <span className="ml-2 text-xs text-muted-foreground">Page {current} of {last}</span>
    </div>
  );
}
