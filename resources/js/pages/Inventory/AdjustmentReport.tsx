import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  ArrowLeft,
  ArrowRight,
  Download,
  SlidersHorizontal,
  TrendingUp,
  TrendingDown,
  Minus,
  AlertTriangle,
  CheckCircle,
  XCircle,
  Clock,
  BarChart3,
  Warehouse,
  User,
  Tag,
  Zap,
} from 'lucide-react';
import Paginator from '@/components/Paginator';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface Summary {
  total: number;
  pending: number;
  approved: number;
  rejected: number;
  positive_count: number;
  negative_count: number;
  zero_count: number;
  total_added: number;
  total_deducted: number;
  total_units_moved: number;
}

interface ReasonRow {
  reason_code: string;
  count: number;
  approved: number;
  pending: number;
  rejected: number;
  net_variance: number;
}

interface WarehouseRow {
  warehouse_name: string;
  warehouse_code: string;
  count: number;
  approved: number;
  pending: number;
  total_added: number;
  total_deducted: number;
}

interface SubmitterRow {
  submitter_name: string;
  count: number;
  approved: number;
  rejected: number;
  pending: number;
}

interface HourRow {
  hour: number;
  count: number;
  approved: number;
}

interface AdjRow {
  id: number;
  reason_code: string;
  reason_notes?: string;
  variance: number;
  quantity_before: number;
  quantity_after: number;
  status: string;
  created_at: string;
  approved_at?: string;
  item_name: string;
  item_sku: string;
  item_type: string;
  warehouse_name: string;
  warehouse_code?: string;
  submitted_by?: string;
  approved_by?: string;
}

interface WarehouseOption {
  id: number;
  name: string;
  code: string;
}

interface Props {
  summary: Summary;
  by_reason: ReasonRow[];
  by_warehouse: WarehouseRow[];
  by_submitter: SubmitterRow[];
  by_hour: HourRow[];
  top_impact: AdjRow[];
  pending_rows: AdjRow[];
  rows: PaginatedResponse<AdjRow>;
  warehouses: WarehouseOption[];
  filters: {
    from?: string;
    to?: string;
    status?: string;
    warehouse_id?: string;
    reason_code?: string;
  };
  period: { from: string; to: string };
}

const REASON_CODES = [
  'CYCLE_COUNT',
  'PHYSICAL_COUNT',
  'DAMAGE',
  'EXPIRED',
  'THEFT',
  'SYSTEM_ERROR',
  'RETURN_TO_STOCK',
  'TRANSFER',
  'OTHER',
];

const REASON_LABELS: Record<string, string> = {
  CYCLE_COUNT: 'Cycle Count',
  PHYSICAL_COUNT: 'Physical Count',
  DAMAGE: 'Damage',
  EXPIRED: 'Expired',
  THEFT: 'Theft',
  SYSTEM_ERROR: 'System Error',
  RETURN_TO_STOCK: 'Return to Stock',
  TRANSFER: 'Transfer',
  OTHER: 'Other',
};

export default function AdjustmentReport({
  summary,
  by_reason,
  by_warehouse,
  by_submitter,
  by_hour,
  top_impact,
  pending_rows,
  rows,
  warehouses,
  filters,
  period,
}: Props) {
  const isToday =
    period.from === period.to && period.from === new Date().toISOString().slice(0, 10);

  function apply(overrides: Record<string, string>) {
    router.get(
      '/inventory/adjustments/report',
      { ...filters, ...overrides },
      {
        preserveState: true,
        replace: true,
      }
    );
  }

  function setToday() {
    const today = new Date().toISOString().slice(0, 10);
    apply({ from: today, to: today });
  }

  const downloadUrl = `/inventory/adjustments/report/download?${new URLSearchParams(
    Object.entries(filters).filter(([, v]) => !!v) as [string, string][]
  ).toString()}`;

  const maxBarCount = Math.max(...by_reason.map((r) => r.count), 1);
  const maxHour = Math.max(...by_hour.map((h) => h.count), 1);

  return (
    <AppLayout>
      <Head title="Adjustment Report" />

      {/* Pending action banner */}
      {summary.pending > 0 && (
        <div className="border-b border-warning/30 bg-warning/5 px-6 py-2.5">
          <div className="flex items-center gap-2">
            <AlertTriangle className="h-4 w-4 shrink-0 text-warning" />
            <span className="text-sm font-medium text-warning">
              <strong>{summary.pending}</strong> adjustment{summary.pending > 1 ? 's' : ''} still
              pending approval in this period.
            </span>
            <Link
              href="/inventory/adjustments?status=PENDING"
              className="ml-auto text-xs text-warning underline underline-offset-2 hover:text-warning/80"
            >
              Review →
            </Link>
          </div>
        </div>
      )}

      <div className="space-y-4 p-4 sm:space-y-6 sm:p-6">
        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <Link
                href="/inventory/adjustments"
                className="text-muted-foreground hover:text-foreground"
              >
                <ArrowLeft className="h-4 w-4" />
              </Link>
              <h1 className="text-2xl font-bold tracking-tight">Adjustment Report</h1>
              {isToday && (
                <span className="rounded-full bg-info/10 px-2 py-0.5 text-xs font-semibold text-info">
                  Today
                </span>
              )}
            </div>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {period.from === period.to
                ? `Date: ${period.from}`
                : `Period: ${period.from} → ${period.to}`}
              {' · '}
              {summary.total.toLocaleString()} total record{summary.total !== 1 ? 's' : ''}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" onClick={setToday}>
              Today
            </Button>
            <a href={downloadUrl}>
              <Button variant="outline" size="sm">
                <Download className="mr-1.5 h-4 w-4" />
                Export CSV
              </Button>
            </a>
            <Link href="/inventory/adjustments">
              <Button variant="outline" size="sm">
                <SlidersHorizontal className="mr-1.5 h-4 w-4" />
                All Adjustments
              </Button>
            </Link>
          </div>
        </div>

        {/* Filter bar */}
        <Card>
          <CardContent className="flex flex-wrap items-end gap-3 p-4">
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">From</label>
              <Input
                type="date"
                value={filters.from ?? period.from}
                onChange={(e) => apply({ from: e.target.value })}
                className="w-36"
              />
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">To</label>
              <Input
                type="date"
                value={filters.to ?? period.to}
                onChange={(e) => apply({ to: e.target.value })}
                className="w-36"
              />
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Status</label>
              <Select
                value={filters.status ?? 'all'}
                onValueChange={(v) => apply({ status: v === 'all' ? '' : v })}
              >
                <SelectTrigger className="w-32">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All</SelectItem>
                  <SelectItem value="PENDING">Pending</SelectItem>
                  <SelectItem value="APPROVED">Approved</SelectItem>
                  <SelectItem value="REJECTED">Rejected</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Warehouse</label>
              <Select
                value={filters.warehouse_id ?? 'all'}
                onValueChange={(v) => apply({ warehouse_id: v === 'all' ? '' : v })}
              >
                <SelectTrigger className="w-44">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Warehouses</SelectItem>
                  {warehouses.map((wh) => (
                    <SelectItem key={wh.id} value={String(wh.id)}>
                      {wh.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Reason</label>
              <Select
                value={filters.reason_code ?? 'all'}
                onValueChange={(v) => apply({ reason_code: v === 'all' ? '' : v })}
              >
                <SelectTrigger className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Reasons</SelectItem>
                  {REASON_CODES.map((r) => (
                    <SelectItem key={r} value={r}>
                      {REASON_LABELS[r] ?? r}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>

        {/* KPI Summary Row */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <KpiTile
            label="Total"
            value={summary.total}
            icon={<SlidersHorizontal className="h-4 w-4" />}
            accent="neutral"
          />
          <KpiTile
            label="Pending"
            value={summary.pending}
            icon={<Clock className="h-4 w-4" />}
            accent="warning"
            pulse={summary.pending > 0}
          />
          <KpiTile
            label="Approved"
            value={summary.approved}
            icon={<CheckCircle className="h-4 w-4" />}
            accent="success"
          />
          <KpiTile
            label="Rejected"
            value={summary.rejected}
            icon={<XCircle className="h-4 w-4" />}
            accent="danger"
          />
          <KpiTile
            label="Units Added"
            value={`+${Number(summary.total_added).toLocaleString()}`}
            icon={<TrendingUp className="h-4 w-4" />}
            accent="success"
          />
          <KpiTile
            label="Units Deducted"
            value={`-${Number(summary.total_deducted).toLocaleString()}`}
            icon={<TrendingDown className="h-4 w-4" />}
            accent="danger"
          />
        </div>

        {/* Variance direction breakdown */}
        <Card className="p-4">
          <div className="mb-3 flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-sm font-medium">
              <BarChart3 className="h-4 w-4 text-muted-foreground" />
              Variance Direction Breakdown
            </span>
            <span className="text-xs text-muted-foreground">
              of {summary.total} total adjustments
            </span>
          </div>
          <div className="flex h-3 w-full overflow-hidden rounded-full bg-muted">
            {summary.total > 0 && (
              <>
                <div
                  className="h-full bg-success transition-all"
                  style={{ width: `${(summary.positive_count / summary.total) * 100}%` }}
                  title={`${summary.positive_count} positive`}
                />
                <div
                  className="h-full bg-destructive transition-all"
                  style={{ width: `${(summary.negative_count / summary.total) * 100}%` }}
                  title={`${summary.negative_count} negative`}
                />
                <div
                  className="h-full bg-muted-foreground/40 flex-1"
                  title={`${summary.zero_count} zero`}
                />
              </>
            )}
          </div>
          <div className="mt-2 flex flex-wrap gap-5 text-xs text-muted-foreground">
            <span className="flex items-center gap-1">
              <span className="h-2 w-3 rounded-sm bg-success inline-block" />
              Stock added: {summary.positive_count}
            </span>
            <span className="flex items-center gap-1">
              <span className="h-2 w-3 rounded-sm bg-destructive inline-block" />
              Stock reduced: {summary.negative_count}
            </span>
            <span className="flex items-center gap-1">
              <span className="h-2 w-3 rounded-sm bg-muted-foreground/40 inline-block" />
              No change: {summary.zero_count}
            </span>
            <span className="ml-auto font-medium text-foreground">
              Total units moved (approved): {Number(summary.total_units_moved).toLocaleString()}
            </span>
          </div>
        </Card>

        {/* Row 2: Reason breakdown + Hourly activity */}
        <div className="grid gap-4 lg:grid-cols-5">
          {/* By reason — 3/5 */}
          <Card className="lg:col-span-3">
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-1.5 text-base">
                <Tag className="h-4 w-4 text-muted-foreground" />
                Adjustments by Reason
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              {by_reason.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">
                  No data for this period.
                </p>
              ) : (
                <div className="divide-y divide-border">
                  {by_reason.map((r) => (
                    <div key={r.reason_code} className="px-4 py-2.5">
                      <div className="mb-1 flex items-center justify-between">
                        <span className="text-sm font-medium">
                          {REASON_LABELS[r.reason_code] ?? r.reason_code}
                        </span>
                        <span className="text-sm tabular-nums font-semibold">{r.count}</span>
                      </div>
                      <div className="mb-1.5 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div
                          className="h-full bg-info rounded-full transition-all"
                          style={{ width: `${(r.count / maxBarCount) * 100}%` }}
                        />
                      </div>
                      <div className="flex gap-3 text-xs text-muted-foreground">
                        <span className="text-success font-medium">✓ {r.approved} approved</span>
                        {r.pending > 0 && (
                          <span className="text-warning font-medium">⏳ {r.pending} pending</span>
                        )}
                        {r.rejected > 0 && (
                          <span className="text-destructive">✗ {r.rejected} rejected</span>
                        )}
                        <span
                          className={`ml-auto font-semibold tabular-nums ${Number(r.net_variance) > 0 ? 'text-success' : Number(r.net_variance) < 0 ? 'text-destructive' : 'text-muted-foreground'}`}
                        >
                          {Number(r.net_variance) > 0
                            ? `+${Number(r.net_variance)}`
                            : Number(r.net_variance)}{' '}
                          net
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Hourly activity — 2/5 (only for single-day view) */}
          <Card className="lg:col-span-2">
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-1.5 text-base">
                <BarChart3 className="h-4 w-4 text-muted-foreground" />
                {by_hour.length > 0 ? 'Hourly Activity' : 'Submitter Summary'}
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              {by_hour.length > 0 ? (
                <div className="px-4 pb-3 pt-2">
                  <div className="flex items-end gap-0.5 h-28 w-full">
                    {Array.from({ length: 24 }, (_, h) => {
                      const d = by_hour.find((x) => Number(x.hour) === h);
                      const count = d?.count ?? 0;
                      const approved = d?.approved ?? 0;
                      return (
                        <div
                          key={h}
                          className="group flex flex-1 flex-col justify-end gap-px"
                          title={`${h}:00 — ${count} total, ${approved} approved`}
                        >
                          {count > 0 && (
                            <>
                              <div
                                className="w-full rounded-t bg-info/50 group-hover:bg-info/70 min-h-[2px] transition-colors"
                                style={{ height: `${((count - approved) / maxHour) * 100}%` }}
                              />
                              <div
                                className="w-full bg-info group-hover:bg-info/80 min-h-[2px] transition-colors"
                                style={{ height: `${(approved / maxHour) * 100}%` }}
                              />
                            </>
                          )}
                          {count === 0 && <div className="w-full bg-muted h-[2px]" />}
                        </div>
                      );
                    })}
                  </div>
                  <div className="mt-2 flex justify-between text-[10px] text-muted-foreground">
                    <span>12 AM</span>
                    <span>6 AM</span>
                    <span>12 PM</span>
                    <span>6 PM</span>
                    <span>11 PM</span>
                  </div>
                  <div className="mt-2 flex gap-4 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1">
                      <span className="h-2 w-3 bg-info rounded-sm inline-block" />
                      Approved
                    </span>
                    <span className="flex items-center gap-1">
                      <span className="h-2 w-3 bg-info/50 rounded-sm inline-block" />
                      Pending/Other
                    </span>
                  </div>
                </div>
              ) : (
                <div className="divide-y divide-border">
                  {by_submitter.slice(0, 8).map((s, i) => (
                    <div key={i} className="flex items-center gap-3 px-4 py-2.5">
                      <User className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{s.submitter_name ?? '—'}</p>
                        <p className="text-xs text-muted-foreground">
                          {s.count} submitted · {s.approved} approved
                        </p>
                      </div>
                      {s.pending > 0 && (
                        <span className="rounded-full bg-warning/10 px-1.5 py-0.5 text-[10px] font-semibold text-warning">
                          {s.pending} pending
                        </span>
                      )}
                    </div>
                  ))}
                  {by_submitter.length === 0 && (
                    <p className="py-8 text-center text-sm text-muted-foreground">No data.</p>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Row 3: Warehouse breakdown + Submitter (multi-day) / Hourly submitter card */}
        <div className="grid gap-4 lg:grid-cols-2">
          {/* By warehouse */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-1.5 text-base">
                <Warehouse className="h-4 w-4 text-muted-foreground" />
                By Warehouse
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              {by_warehouse.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">
                  No data for this period.
                </p>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent">
                      <TableHead>Warehouse</TableHead>
                      <TableHead className="text-right">Total</TableHead>
                      <TableHead className="text-right">Approved</TableHead>
                      <TableHead className="text-right text-success">+Added</TableHead>
                      <TableHead className="text-right text-destructive">−Deducted</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {by_warehouse.map((wh, i) => (
                      <TableRow key={i}>
                        <TableCell>
                          <div className="font-medium text-sm">{wh.warehouse_name ?? '—'}</div>
                          {wh.warehouse_code && (
                            <div className="font-mono text-[11px] text-muted-foreground">
                              {wh.warehouse_code}
                            </div>
                          )}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">{wh.count}</TableCell>
                        <TableCell className="text-right tabular-nums text-success">
                          {wh.approved}
                        </TableCell>
                        <TableCell className="text-right tabular-nums font-medium text-success">
                          +{Number(wh.total_added).toLocaleString()}
                        </TableCell>
                        <TableCell className="text-right tabular-nums font-medium text-destructive">
                          −{Number(wh.total_deducted).toLocaleString()}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>

          {/* By submitter (multi-day full; today shows if hour chart above, show here for completeness) */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-1.5 text-base">
                <User className="h-4 w-4 text-muted-foreground" />
                By Submitter
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              {by_submitter.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">
                  No submissions in this period.
                </p>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent">
                      <TableHead>Staff</TableHead>
                      <TableHead className="text-right">Submitted</TableHead>
                      <TableHead className="text-right text-success">Approved</TableHead>
                      <TableHead className="text-right text-destructive">Rejected</TableHead>
                      <TableHead className="text-right text-warning">Pending</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {by_submitter.map((s, i) => (
                      <TableRow key={i}>
                        <TableCell className="font-medium text-sm">
                          {s.submitter_name ?? '—'}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">{s.count}</TableCell>
                        <TableCell className="text-right tabular-nums text-success">
                          {s.approved}
                        </TableCell>
                        <TableCell className="text-right tabular-nums text-destructive">
                          {s.rejected}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                          {s.pending > 0 ? (
                            <span className="font-semibold text-warning">{s.pending}</span>
                          ) : (
                            <span className="text-muted-foreground">0</span>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Pending items needing action */}
        {pending_rows.length > 0 && (
          <Card className="border-warning/30">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-base text-warning">
                <AlertTriangle className="h-4 w-4" />
                Pending Approval ({pending_rows.length})
              </CardTitle>
              <Link href="/inventory/adjustments?status=PENDING">
                <Button
                  size="sm"
                  variant="outline"
                  className="border-warning/30 text-warning hover:bg-warning/10"
                >
                  Manage <ArrowRight className="ml-1 h-3.5 w-3.5" />
                </Button>
              </Link>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent">
                    <TableHead>Item</TableHead>
                    <TableHead>Warehouse</TableHead>
                    <TableHead>Reason</TableHead>
                    <TableHead className="text-right">Before</TableHead>
                    <TableHead className="text-right">After</TableHead>
                    <TableHead className="text-right">Variance</TableHead>
                    <TableHead>Submitted by</TableHead>
                    <TableHead>Submitted at</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {pending_rows.map((adj) => (
                    <TableRow key={adj.id} className="bg-warning/5 hover:bg-warning/10">
                      <TableCell>
                        <div className="text-sm font-medium">{adj.item_name}</div>
                        <div className="font-mono text-[11px] text-muted-foreground">
                          {adj.item_sku}
                        </div>
                      </TableCell>
                      <TableCell className="text-sm">{adj.warehouse_name}</TableCell>
                      <TableCell>
                        <span className="inline-block rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium">
                          {REASON_LABELS[adj.reason_code] ?? adj.reason_code}
                        </span>
                      </TableCell>
                      <TableCell className="text-right font-mono tabular-nums">
                        {adj.quantity_before}
                      </TableCell>
                      <TableCell className="text-right font-mono tabular-nums">
                        {adj.quantity_after}
                      </TableCell>
                      <TableCell className="text-right">
                        <VarianceCell variance={adj.variance} />
                      </TableCell>
                      <TableCell className="text-sm">{adj.submitted_by ?? '—'}</TableCell>
                      <TableCell className="text-xs text-muted-foreground whitespace-nowrap">
                        {formatDate(adj.created_at)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Top impact adjustments */}
        {top_impact.length > 0 && (
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-1.5 text-base">
                <Zap className="h-4 w-4 text-warning" />
                High-Impact Adjustments (Approved, by Variance Size)
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent">
                    <TableHead>Item</TableHead>
                    <TableHead>Warehouse</TableHead>
                    <TableHead>Reason</TableHead>
                    <TableHead className="text-right">Before</TableHead>
                    <TableHead className="text-right">After</TableHead>
                    <TableHead className="text-right">Variance</TableHead>
                    <TableHead>Submitted by</TableHead>
                    <TableHead>Approved by</TableHead>
                    <TableHead>Approved at</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {top_impact.map((adj) => (
                    <TableRow key={adj.id}>
                      <TableCell>
                        <div className="text-sm font-medium">{adj.item_name}</div>
                        <div className="font-mono text-[11px] text-muted-foreground">
                          {adj.item_sku}
                        </div>
                      </TableCell>
                      <TableCell className="text-sm">{adj.warehouse_name}</TableCell>
                      <TableCell>
                        <span className="inline-block rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium">
                          {REASON_LABELS[adj.reason_code] ?? adj.reason_code}
                        </span>
                        {adj.reason_notes && (
                          <div className="mt-0.5 max-w-[160px] truncate text-[11px] text-muted-foreground">
                            {adj.reason_notes}
                          </div>
                        )}
                      </TableCell>
                      <TableCell className="text-right font-mono tabular-nums">
                        {adj.quantity_before}
                      </TableCell>
                      <TableCell className="text-right font-mono tabular-nums">
                        {adj.quantity_after}
                      </TableCell>
                      <TableCell className="text-right">
                        <VarianceCell variance={adj.variance} />
                      </TableCell>
                      <TableCell className="text-sm">{adj.submitted_by ?? '—'}</TableCell>
                      <TableCell className="text-sm">{adj.approved_by ?? '—'}</TableCell>
                      <TableCell className="text-xs text-muted-foreground whitespace-nowrap">
                        {adj.approved_at ? formatDate(adj.approved_at) : '—'}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Full drill-down table */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-base">All Adjustments — Drill-down</CardTitle>
            <a href={downloadUrl}>
              <Button variant="outline" size="sm">
                <Download className="mr-1.5 h-3.5 w-3.5" />
                Export CSV
              </Button>
            </a>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent">
                  <TableHead>Item</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Warehouse</TableHead>
                  <TableHead>Reason</TableHead>
                  <TableHead className="text-right">Before</TableHead>
                  <TableHead className="text-right">After</TableHead>
                  <TableHead className="text-right">Variance</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Submitted by</TableHead>
                  <TableHead>Date</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.data.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={10} className="py-16 text-center">
                      <SlidersHorizontal className="mx-auto mb-3 h-8 w-8 text-muted-foreground/30" />
                      <p className="font-medium text-muted-foreground">
                        No adjustments in this period
                      </p>
                    </TableCell>
                  </TableRow>
                ) : (
                  rows.data.map((adj) => (
                    <TableRow key={adj.id}>
                      <TableCell>
                        <div className="text-sm font-medium leading-tight">{adj.item_name}</div>
                        <div className="font-mono text-[11px] text-muted-foreground">
                          {adj.item_sku}
                        </div>
                      </TableCell>
                      <TableCell>
                        <span
                          className={`inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold ${
                            adj.item_type === 'product'
                              ? 'bg-info/10 text-info'
                              : 'bg-primary/10 text-primary'
                          }`}
                        >
                          {adj.item_type === 'product' ? 'Product' : 'Supply'}
                        </span>
                      </TableCell>
                      <TableCell className="text-sm">{adj.warehouse_name}</TableCell>
                      <TableCell>
                        <span className="inline-block rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium">
                          {REASON_LABELS[adj.reason_code] ?? adj.reason_code}
                        </span>
                      </TableCell>
                      <TableCell className="text-right font-mono tabular-nums">
                        {adj.quantity_before}
                      </TableCell>
                      <TableCell className="text-right font-mono tabular-nums">
                        {adj.quantity_after}
                      </TableCell>
                      <TableCell className="text-right">
                        <VarianceCell variance={adj.variance} />
                      </TableCell>
                      <TableCell>
                        <StatusBadge status={adj.status} />
                      </TableCell>
                      <TableCell className="text-sm">{adj.submitted_by ?? '—'}</TableCell>
                      <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                        {formatDate(adj.created_at)}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* Pagination */}
        <Paginator
          pagination={rows}
          url="/inventory/adjustments/report"
          params={filters as Record<string, string>}
        />
      </div>
    </AppLayout>
  );
}

function KpiTile({
  label,
  value,
  icon,
  accent,
  pulse,
}: {
  label: string;
  value: string | number;
  icon: React.ReactNode;
  accent: 'neutral' | 'warning' | 'success' | 'danger';
  pulse?: boolean;
}) {
  const map = {
    neutral: { border: 'border-l-border', text: '' },
    warning: { border: 'border-l-warning', text: 'text-warning' },
    success: { border: 'border-l-success', text: 'text-success' },
    danger: { border: 'border-l-destructive', text: 'text-destructive' },
  }[accent];
  return (
    <Card className={`border-l-4 ${map.border}`}>
      <CardContent className="p-4">
        <div className="mb-1.5 flex items-center gap-1.5 text-muted-foreground">
          {icon}
          {pulse && <span className="h-1.5 w-1.5 rounded-full bg-warning animate-pulse" />}
          <span className="text-[11px] font-medium uppercase tracking-wide">{label}</span>
        </div>
        <p className={`text-2xl font-bold tabular-nums ${map.text}`}>{value}</p>
      </CardContent>
    </Card>
  );
}

function VarianceCell({ variance }: { variance: number }) {
  const v = Number(variance);
  return (
    <span
      className={`inline-flex items-center gap-0.5 font-bold tabular-nums ${
        v > 0 ? 'text-success' : v < 0 ? 'text-destructive' : 'text-muted-foreground'
      }`}
    >
      {v > 0 ? (
        <TrendingUp className="h-3.5 w-3.5" />
      ) : v < 0 ? (
        <TrendingDown className="h-3.5 w-3.5" />
      ) : (
        <Minus className="h-3.5 w-3.5" />
      )}
      {v > 0 ? `+${v}` : v}
    </span>
  );
}

function StatusBadge({ status }: { status: string }) {
  const map: Record<string, { cls: string; dot: string; label: string }> = {
    PENDING: {
      cls: 'bg-warning/10 text-warning',
      dot: 'bg-warning animate-pulse',
      label: 'Pending',
    },
    APPROVED: { cls: 'bg-success/10 text-success', dot: 'bg-success', label: 'Approved' },
    REJECTED: {
      cls: 'bg-destructive/10 text-destructive',
      dot: 'bg-destructive',
      label: 'Rejected',
    },
  };
  const { cls, dot, label } = map[status] ?? {
    cls: 'bg-muted text-muted-foreground',
    dot: 'bg-muted-foreground',
    label: status,
  };
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${cls}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${dot}`} />
      {label}
    </span>
  );
}
