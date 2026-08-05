import { useState } from 'react';
import { toast } from 'sonner';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog';
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
  CheckCircle2,
  SkipForward,
  Flag,
  Ban,
  TrendingUp,
  TrendingDown,
  Minus,
} from 'lucide-react';
import { formatDate, formatCurrency } from '@/lib/utils';

interface SessionDetail {
  id: number;
  name: string;
  warehouse: string;
  warehouse_id: number;
  status: string;
  started_by: string | null;
  finalized_by: string | null;
  started_at: string | null;
  finalized_at: string | null;
  notes: string | null;
}

interface ItemRow {
  id: number;
  product_id: number;
  sku: string;
  name: string;
  location: string | null;
  system_qty: number;
  counted_qty: number | null;
  variance: number | null;
  variance_pct: number | null;
  unit_cost: number;
  variance_value: number | null;
  status: string;
  counted_at: string | null;
  adjustment_id: number | null;
}

interface Detail {
  session: SessionDetail;
  items: ItemRow[];
  summary: {
    total_items: number;
    counted_items: number;
    variance_items: number;
    total_variance_qty: number;
    total_variance_value: number;
  };
}

function StatusBadge({ status }: { status: string }) {
  const map: Record<string, string> = {
    PENDING: 'bg-muted text-muted-foreground',
    COUNTED: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
    SKIPPED: 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
  };
  return (
    <Badge className={map[status] ?? 'bg-muted'} variant="secondary">
      {status}
    </Badge>
  );
}

function VarianceCell({ variance }: { variance: number | null }) {
  if (variance === null) return <span className="text-muted-foreground">—</span>;
  if (variance === 0)
    return (
      <span className="inline-flex items-center gap-0.5 text-muted-foreground">
        <Minus className="h-3.5 w-3.5" />0
      </span>
    );
  return (
    <span
      className={`inline-flex items-center gap-0.5 font-bold ${
        variance > 0 ? 'text-success' : 'text-destructive'
      }`}
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

export default function CycleCountDetail({ detail }: { detail: Detail }) {
  const { session, items, summary } = detail;
  const [countItem, setCountItem] = useState<ItemRow | null>(null);
  const [countedQty, setCountedQty] = useState('0');
  const [countNotes, setCountNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [finalizing, setFinalizing] = useState(false);

  const isFinalized = session.status === 'FINALIZED';
  const isCancelled = session.status === 'CANCELLED';
  const isReadOnly = isFinalized || isCancelled;

  function openCountDialog(item: ItemRow) {
    setCountItem(item);
    setCountedQty(String(item.counted_qty ?? item.system_qty));
    setCountNotes('');
  }

  function submitCount() {
    if (!countItem) return;
    setSubmitting(true);
    router.post(
      `/inventory/cycle-counts/items/${countItem.id}/count`,
      { counted_qty: countedQty, notes: countNotes || undefined },
      {
        onSuccess: () => {
          toast.success('Count recorded.');
          setCountItem(null);
        },
        onError: () => toast.error('Failed to record count.'),
        onFinish: () => setSubmitting(false),
        preserveScroll: true,
      }
    );
  }

  function skipItem(itemId: number) {
    router.post(
      `/inventory/cycle-counts/items/${itemId}/skip`,
      {},
      {
        onSuccess: () => toast.success('Item skipped.'),
        onError: () => toast.error('Failed to skip item.'),
        preserveScroll: true,
      }
    );
  }

  function finalize() {
    setFinalizing(true);
    router.post(
      `/inventory/cycle-counts/${session.id}/finalize`,
      {},
      {
        onSuccess: (page) => {
          const flash = (page as any)?.props?.flash;
          toast.success(flash?.success ?? 'Session finalized.');
        },
        onError: () => toast.error('Failed to finalize session.'),
        onFinish: () => setFinalizing(false),
        preserveScroll: true,
      }
    );
  }

  function cancel() {
    router.post(
      `/inventory/cycle-counts/${session.id}/cancel`,
      {},
      {
        onSuccess: () => toast.success('Session cancelled.'),
        onError: () => toast.error('Failed to cancel session.'),
        preserveScroll: true,
      }
    );
  }

  return (
    <AppLayout>
      <Head title={`Cycle Count — ${session.name}`} />
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <Link
              href="/inventory/cycle-counts"
              className="mb-1 flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-3 w-3" /> Cycle Counts
            </Link>
            <h1 className="text-2xl font-bold tracking-tight">{session.name}</h1>
            <p className="text-sm text-muted-foreground">
              {session.warehouse} · Started{' '}
              {session.started_by ? `by ${session.started_by}` : 'system'}{' '}
              {session.started_at ? `on ${formatDate(session.started_at)}` : ''}
            </p>
          </div>
          {!isReadOnly && (
            <div className="flex gap-2">
              <Button variant="outline" onClick={cancel}>
                <Ban className="mr-1.5 h-4 w-4" />
                Cancel
              </Button>
              <Button onClick={finalize} disabled={finalizing || summary.counted_items === 0}>
                <Flag className="mr-1.5 h-4 w-4" />
                {finalizing ? 'Finalizing…' : 'Finalize'}
              </Button>
            </div>
          )}
        </div>

        {/* Summary cards */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
          <Card>
            <CardContent className="p-4">
              <p className="text-sm text-muted-foreground">Total Items</p>
              <p className="mt-1 text-2xl font-bold">{summary.total_items}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-sm text-muted-foreground">Counted</p>
              <p className="mt-1 text-2xl font-bold">{summary.counted_items}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-sm text-muted-foreground">Variances</p>
              <p className="mt-1 text-2xl font-bold">{summary.variance_items}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-sm text-muted-foreground">Net Variance Qty</p>
              <p
                className={`mt-1 text-2xl font-bold ${
                  summary.total_variance_qty > 0
                    ? 'text-success'
                    : summary.total_variance_qty < 0
                      ? 'text-destructive'
                      : ''
                }`}
              >
                {summary.total_variance_qty > 0 ? '+' : ''}
                {summary.total_variance_qty}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-sm text-muted-foreground">Variance Value</p>
              <p
                className={`mt-1 text-2xl font-bold ${
                  summary.total_variance_value > 0
                    ? 'text-success'
                    : summary.total_variance_value < 0
                      ? 'text-destructive'
                      : ''
                }`}
              >
                {formatCurrency(summary.total_variance_value)}
              </p>
            </CardContent>
          </Card>
        </div>

        {/* Items table */}
        <Card>
          <CardHeader>
            <CardTitle>Count Items</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>SKU</TableHead>
                    <TableHead>Product</TableHead>
                    <TableHead>Location</TableHead>
                    <TableHead className="text-right">System Qty</TableHead>
                    <TableHead className="text-right">Counted Qty</TableHead>
                    <TableHead className="text-right">Variance</TableHead>
                    <TableHead className="text-right">Variance Value</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {items.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={9} className="py-8 text-center text-muted-foreground">
                        No items in this session.
                      </TableCell>
                    </TableRow>
                  ) : (
                    items.map((item) => (
                      <TableRow key={item.id}>
                        <TableCell className="font-mono text-xs">{item.sku}</TableCell>
                        <TableCell className="text-sm font-medium">{item.name}</TableCell>
                        <TableCell className="text-xs text-muted-foreground">
                          {item.location ?? '—'}
                        </TableCell>
                        <TableCell className="text-right font-mono tabular-nums">
                          {item.system_qty}
                        </TableCell>
                        <TableCell className="text-right font-mono tabular-nums">
                          {item.counted_qty ?? '—'}
                        </TableCell>
                        <TableCell className="text-right">
                          <VarianceCell variance={item.variance} />
                        </TableCell>
                        <TableCell className="text-right font-mono text-xs">
                          {item.variance_value !== null ? formatCurrency(item.variance_value) : '—'}
                        </TableCell>
                        <TableCell>
                          <StatusBadge status={item.status} />
                        </TableCell>
                        <TableCell className="text-right">
                          {!isReadOnly && item.status === 'PENDING' && (
                            <div className="flex justify-end gap-1">
                              <Button
                                size="sm"
                                variant="ghost"
                                className="h-8 px-2"
                                onClick={() => openCountDialog(item)}
                              >
                                <CheckCircle2 className="h-4 w-4 text-success" />
                              </Button>
                              <Button
                                size="sm"
                                variant="ghost"
                                className="h-8 px-2"
                                onClick={() => skipItem(item.id)}
                              >
                                <SkipForward className="h-4 w-4 text-muted-foreground" />
                              </Button>
                            </div>
                          )}
                          {!isReadOnly && item.status === 'COUNTED' && (
                            <Button
                              size="sm"
                              variant="ghost"
                              className="h-8 px-2 text-xs"
                              onClick={() => openCountDialog(item)}
                            >
                              Edit
                            </Button>
                          )}
                          {item.adjustment_id && (
                            <Link
                              href={`/inventory/adjustments?status=PENDING`}
                              className="ml-1 text-xs text-blue-600 hover:underline"
                            >
                              adj#{item.adjustment_id}
                            </Link>
                          )}
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Count Dialog */}
      <Dialog open={countItem !== null} onOpenChange={(o) => !o && setCountItem(null)}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>Record Count</DialogTitle>
            <DialogDescription>
              {countItem?.sku} — {countItem?.name}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <div className="rounded-lg border bg-muted/30 px-3 py-2 text-sm">
              <span className="text-muted-foreground">System quantity: </span>
              <span className="font-mono font-bold">{countItem?.system_qty}</span>
            </div>
            <div className="space-y-1.5">
              <Label>
                Counted Quantity <span className="text-destructive">*</span>
              </Label>
              <Input
                type="number"
                min={0}
                value={countedQty}
                onChange={(e) => setCountedQty(e.target.value)}
                autoFocus
              />
            </div>
            <div className="space-y-1.5">
              <Label>Notes (optional)</Label>
              <Input
                value={countNotes}
                onChange={(e) => setCountNotes(e.target.value)}
                placeholder="e.g. Damaged packaging, partial count…"
              />
            </div>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={() => setCountItem(null)}>
              Cancel
            </Button>
            <Button onClick={submitCount} disabled={submitting}>
              {submitting ? 'Saving…' : 'Save Count'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
