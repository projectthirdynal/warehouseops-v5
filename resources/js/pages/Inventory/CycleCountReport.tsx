import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
  TrendingUp,
  TrendingDown,
  Minus,
  Target,
  ClipboardList,
  DollarSign,
  AlertTriangle,
  CheckCircle2,
} from 'lucide-react';
import { formatDate, formatCurrency } from '@/lib/utils';

interface ReportRow {
  session_id: number;
  session_name: string;
  sku: string;
  name: string;
  system_qty: number;
  counted_qty: number | null;
  variance: number | null;
  variance_value: number;
  finalized_at: string | null;
}
interface ReportData {
  rows: ReportRow[];
  summary: {
    total_counted: number;
    accurate_count: number;
    variance_count: number;
    total_shortage_qty: number;
    total_overage_qty: number;
    total_variance_value: number;
    accuracy_rate: number;
  };
}
interface Props {
  report: ReportData;
  filters: { from?: string; to?: string; warehouse_id?: string };
  warehouses: { id: number; name: string; code: string }[];
}

function VarianceBadge({ variance }: { variance: number | null }) {
  if (variance === null) return <span className="text-muted-foreground">—</span>;
  if (variance === 0)
    return (
      <span className="inline-flex items-center gap-0.5 text-muted-foreground">
        <Minus className="h-3.5 w-3.5" />0
      </span>
    );
  return (
    <span
      className={`inline-flex items-center gap-0.5 font-bold ${variance > 0 ? 'text-success' : 'text-destructive'}`}
    >
      {variance > 0 ? (
        <TrendingUp className="h-3.5 w-3.5" />
      ) : (
        <TrendingDown className="h-3.5 w-3.5" />
      )}
      {variance > 0 ? `+${variance}` : variance}
    </span>
  );
}

export default function CycleCountReport({ report, filters, warehouses }: Props) {
  const { rows, summary } = report;
  const [from, setFrom] = useState(filters.from ?? '');
  const [to, setTo] = useState(filters.to ?? '');
  const [warehouseId, setWarehouseId] = useState(filters.warehouse_id ?? 'all');

  function applyFilters() {
    const params: Record<string, string> = {};
    if (from) params.from = from;
    if (to) params.to = to;
    if (warehouseId !== 'all') params.warehouse_id = warehouseId;
    router.get('/inventory/cycle-counts/report', params, {
      preserveScroll: true,
      preserveState: true,
    });
  }

  function resetFilters() {
    setFrom('');
    setTo('');
    setWarehouseId('all');
    router.get('/inventory/cycle-counts/report', {}, { preserveScroll: true, preserveState: true });
  }

  return (
    <AppLayout>
      <Head title="Cycle Count Variance Report" />
      <div className="space-y-6 p-6">
        <div>
          <Link
            href="/inventory/cycle-counts"
            className="mb-1 flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="h-3 w-3" /> Cycle Counts
          </Link>
          <h1 className="text-2xl font-bold tracking-tight">Variance Report</h1>
          <p className="text-sm text-muted-foreground">
            Aggregated variance analysis across finalized sessions
          </p>
        </div>

        {/* Summary cards */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <ClipboardList className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Counted</p>
              </div>
              <p className="mt-1 text-2xl font-bold">{summary.total_counted}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <CheckCircle2 className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Accurate</p>
              </div>
              <p className="mt-1 text-2xl font-bold text-success">{summary.accurate_count}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <AlertTriangle className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Variances</p>
              </div>
              <p className="mt-1 text-2xl font-bold text-destructive">{summary.variance_count}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <Target className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Accuracy</p>
              </div>
              <p className="mt-1 text-2xl font-bold">{summary.accuracy_rate}%</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <TrendingDown className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Shortage</p>
              </div>
              <p className="mt-1 text-2xl font-bold text-destructive">
                {summary.total_shortage_qty}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Overage</p>
              </div>
              <p className="mt-1 text-2xl font-bold text-success">{summary.total_overage_qty}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <DollarSign className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Value</p>
              </div>
              <p
                className={`mt-1 text-2xl font-bold ${summary.total_variance_value > 0 ? 'text-success' : summary.total_variance_value < 0 ? 'text-destructive' : ''}`}
              >
                {formatCurrency(summary.total_variance_value)}
              </p>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <Card>
          <CardHeader>
            <CardTitle>Filters</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex flex-wrap items-end gap-3">
              <div className="space-y-1.5">
                <Label className="text-xs">From</Label>
                <Input
                  type="date"
                  value={from}
                  onChange={(e) => setFrom(e.target.value)}
                  className="w-44"
                />
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs">To</Label>
                <Input
                  type="date"
                  value={to}
                  onChange={(e) => setTo(e.target.value)}
                  className="w-44"
                />
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs">Warehouse</Label>
                <Select value={warehouseId} onValueChange={setWarehouseId}>
                  <SelectTrigger className="w-48">
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
              <Button onClick={applyFilters}>Apply</Button>
              <Button variant="outline" onClick={resetFilters}>
                Reset
              </Button>
            </div>
          </CardContent>
        </Card>

        {/* Variance table */}
        <Card>
          <CardHeader>
            <CardTitle>Variance Details ({rows.length})</CardTitle>
          </CardHeader>
          <CardContent>
            {rows.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">
                No variance data for the selected filters.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Session</TableHead>
                      <TableHead>SKU</TableHead>
                      <TableHead>Product</TableHead>
                      <TableHead className="text-right">System</TableHead>
                      <TableHead className="text-right">Counted</TableHead>
                      <TableHead className="text-right">Variance</TableHead>
                      <TableHead className="text-right">Value</TableHead>
                      <TableHead>Finalized</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {rows.map((r, i) => (
                      <TableRow key={`${r.session_id}-${r.sku}-${i}`}>
                        <TableCell className="text-xs text-muted-foreground">
                          {r.session_name}
                        </TableCell>
                        <TableCell className="font-mono text-xs">{r.sku}</TableCell>
                        <TableCell className="text-sm font-medium">{r.name}</TableCell>
                        <TableCell className="text-right font-mono tabular-nums">
                          {r.system_qty}
                        </TableCell>
                        <TableCell className="text-right font-mono tabular-nums">
                          {r.counted_qty ?? '—'}
                        </TableCell>
                        <TableCell className="text-right">
                          <VarianceBadge variance={r.variance} />
                        </TableCell>
                        <TableCell
                          className={`text-right font-mono text-xs ${r.variance_value > 0 ? 'text-success' : r.variance_value < 0 ? 'text-destructive' : ''}`}
                        >
                          {formatCurrency(r.variance_value)}
                        </TableCell>
                        <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                          {r.finalized_at ? formatDate(r.finalized_at) : '—'}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
