import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
  CheckCircle, XCircle, Plus, SlidersHorizontal, ArrowLeft, ArrowRight,
  TrendingUp, TrendingDown, Minus, X, AlertTriangle, BarChart3,
} from 'lucide-react';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface AdjustmentRow {
  id: number;
  reason_code: string;
  reason_notes?: string;
  quantity_before: number;
  quantity_after: number;
  variance: number;
  status: string;
  created_at: string;
  approved_at?: string;
  product_name?: string;
  product_sku?: string;
  supply_name?: string;
  supply_sku?: string;
  warehouse_name?: string;
  warehouse_code?: string;
  submitted_by?: string;
  approved_by?: string;
}

interface Warehouse { id: number; name: string; code: string; }
interface Product   { id: number; name: string; sku: string; }
interface Supply    { id: number; name: string; sku: string; }

interface Props {
  adjustments: PaginatedResponse<AdjustmentRow>;
  stats: { pending: number; approved: number; rejected: number };
  warehouses: Warehouse[];
  products: Product[];
  supplies: Supply[];
  filters: { status?: string; warehouse_id?: string; from?: string; to?: string };
}

const REASON_CODES = [
  'CYCLE_COUNT', 'PHYSICAL_COUNT', 'DAMAGE', 'EXPIRED', 'THEFT',
  'SYSTEM_ERROR', 'RETURN_TO_STOCK', 'TRANSFER', 'OTHER',
];

export default function StockAdjustments({
  adjustments,
  stats = { pending: 0, approved: 0, rejected: 0 },
  warehouses = [],
  products = [],
  supplies = [],
  filters = {},
}: Props) {
  const [newOpen, setNewOpen]           = useState(false);
  const [approveId, setApproveId]       = useState<number | null>(null);
  const [rejectId, setRejectId]         = useState<number | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [approving, setApproving]       = useState(false);

  function applyFilters(overrides: Record<string, string>) {
    router.get('/inventory/adjustments', { ...filters, ...overrides }, { preserveState: true, replace: true });
  }

  function confirmApprove() {
    if (!approveId) return;
    setApproving(true);
    router.post(`/inventory/adjustments/${approveId}/approve`, {}, {
      onFinish: () => { setApproveId(null); setApproving(false); },
      preserveScroll: true,
    });
  }

  function reject() {
    if (!rejectId) return;
    router.post(`/inventory/adjustments/${rejectId}/reject`, { reason: rejectReason }, {
      onSuccess: () => { setRejectId(null); setRejectReason(''); },
      preserveScroll: true,
    });
  }

  const data = adjustments?.data ?? [];
  const lastPage = adjustments?.last_page ?? 1;
  const currentPage = adjustments?.current_page ?? 1;
  const total = adjustments?.total ?? 0;
  const perPage = adjustments?.per_page ?? 25;

  const hasFilters = !!(filters.status || filters.warehouse_id || filters.from || filters.to);

  return (
    <AppLayout>
      <Head title="Stock Adjustments" />
      <div className="space-y-5 p-6">

        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Stock Adjustments</h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Submit and approve physical count variances or stock corrections.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/inventory/adjustments/report">
              <Button variant="outline">
                <BarChart3 className="mr-1.5 h-4 w-4" />Report
              </Button>
            </Link>
            <Button onClick={() => setNewOpen(true)}>
              <Plus className="mr-1.5 h-4 w-4" />New Adjustment
            </Button>
          </div>
        </div>

        {/* Stat cards */}
        <div className="grid grid-cols-3 gap-4">
          <StatCard label="Pending" value={stats.pending} accent="orange" />
          <StatCard label="Approved" value={stats.approved} accent="green" />
          <StatCard label="Rejected" value={stats.rejected} accent="gray" />
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="flex flex-wrap items-end gap-3 p-4">
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Status</label>
              <Select value={filters.status ?? 'all'} onValueChange={v => applyFilters({ status: v === 'all' ? '' : v, page: '1' })}>
                <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Statuses</SelectItem>
                  <SelectItem value="PENDING">Pending</SelectItem>
                  <SelectItem value="APPROVED">Approved</SelectItem>
                  <SelectItem value="REJECTED">Rejected</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Warehouse</label>
              <Select value={filters.warehouse_id ?? 'all'} onValueChange={v => applyFilters({ warehouse_id: v === 'all' ? '' : v, page: '1' })}>
                <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Warehouses</SelectItem>
                  {warehouses.map(wh => <SelectItem key={wh.id} value={String(wh.id)}>{wh.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">From</label>
              <Input type="date" value={filters.from ?? ''} onChange={e => applyFilters({ from: e.target.value })} className="w-36" />
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">To</label>
              <Input type="date" value={filters.to ?? ''} onChange={e => applyFilters({ to: e.target.value })} className="w-36" />
            </div>
            {hasFilters && (
              <Button size="sm" variant="ghost" className="gap-1 text-muted-foreground"
                onClick={() => applyFilters({ status: '', warehouse_id: '', from: '', to: '', page: '1' })}>
                <X className="h-3.5 w-3.5" /> Clear
              </Button>
            )}
          </CardContent>
        </Card>

        {/* Pending banner */}
        {stats.pending > 0 && !filters.status && (
          <div className="flex items-center gap-2 rounded-lg border border-orange-200 bg-orange-50 px-4 py-2.5">
            <AlertTriangle className="h-4 w-4 shrink-0 text-orange-600" />
            <p className="text-sm text-orange-700">
              <strong>{stats.pending}</strong> adjustment{stats.pending > 1 ? 's' : ''} pending approval — review and approve or reject below.
            </p>
            <Button size="sm" variant="outline" className="ml-auto border-orange-300 text-orange-700 hover:bg-orange-100"
              onClick={() => applyFilters({ status: 'PENDING', page: '1' })}>
              Show pending
            </Button>
          </div>
        )}

        {/* Table */}
        <Card className="overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent">
                <TableHead>Item</TableHead>
                <TableHead>Warehouse</TableHead>
                <TableHead>Reason</TableHead>
                <TableHead className="text-right w-20">Before</TableHead>
                <TableHead className="text-right w-20">After</TableHead>
                <TableHead className="text-right w-24">Variance</TableHead>
                <TableHead className="w-28">Status</TableHead>
                <TableHead>Submitted by</TableHead>
                <TableHead className="w-28">Date</TableHead>
                <TableHead className="w-20"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={10} className="py-16 text-center">
                    <SlidersHorizontal className="mx-auto mb-3 h-8 w-8 text-muted-foreground/30" />
                    <p className="font-medium text-muted-foreground">No adjustments found</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {hasFilters ? 'Try clearing your filters.' : 'Create a new adjustment to get started.'}
                    </p>
                    {!hasFilters && (
                      <Button size="sm" className="mt-3" onClick={() => setNewOpen(true)}>
                        <Plus className="mr-1 h-3.5 w-3.5" />New Adjustment
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ) : data.map(adj => (
                <TableRow key={adj.id} className={adj.status === 'PENDING' ? 'bg-orange-50/50 hover:bg-orange-50' : ''}>
                  <TableCell>
                    <div className="text-sm font-medium leading-tight">
                      {adj.product_name ?? adj.supply_name ?? '—'}
                    </div>
                    <div className="font-mono text-[11px] text-muted-foreground">
                      {adj.product_sku ?? adj.supply_sku ?? ''}
                    </div>
                  </TableCell>
                  <TableCell className="text-sm">{adj.warehouse_name ?? '—'}</TableCell>
                  <TableCell>
                    <span className="inline-block rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium">
                      {adj.reason_code.replace(/_/g, ' ')}
                    </span>
                    {adj.reason_notes && (
                      <div className="mt-0.5 max-w-[180px] truncate text-xs text-muted-foreground">{adj.reason_notes}</div>
                    )}
                  </TableCell>
                  <TableCell className="text-right font-mono tabular-nums">{adj.quantity_before}</TableCell>
                  <TableCell className="text-right font-mono tabular-nums">{adj.quantity_after}</TableCell>
                  <TableCell className="text-right">
                    <span className={`inline-flex items-center gap-0.5 font-bold tabular-nums ${
                      adj.variance > 0 ? 'text-emerald-600' : adj.variance < 0 ? 'text-red-600' : 'text-muted-foreground'
                    }`}>
                      {adj.variance > 0
                        ? <TrendingUp className="h-3.5 w-3.5" />
                        : adj.variance < 0
                        ? <TrendingDown className="h-3.5 w-3.5" />
                        : <Minus className="h-3.5 w-3.5" />}
                      {adj.variance > 0 ? `+${adj.variance}` : adj.variance}
                    </span>
                  </TableCell>
                  <TableCell><StatusBadge status={adj.status} /></TableCell>
                  <TableCell className="text-sm">{adj.submitted_by ?? '—'}</TableCell>
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">{formatDate(adj.created_at)}</TableCell>
                  <TableCell>
                    {adj.status === 'PENDING' && (
                      <div className="flex gap-1">
                        <Button size="sm" variant="ghost"
                          className="h-8 w-8 p-0 text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700"
                          title="Approve"
                          onClick={() => setApproveId(adj.id)}>
                          <CheckCircle className="h-4 w-4" />
                        </Button>
                        <Button size="sm" variant="ghost"
                          className="h-8 w-8 p-0 text-red-600 hover:bg-red-50 hover:text-red-700"
                          title="Reject"
                          onClick={() => { setRejectId(adj.id); setRejectReason(''); }}>
                          <XCircle className="h-4 w-4" />
                        </Button>
                      </div>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>

        {/* Pagination */}
        {lastPage > 1 && (
          <div className="flex items-center justify-between">
            <p className="text-xs text-muted-foreground">
              Showing {((currentPage - 1) * perPage) + 1}–{Math.min(currentPage * perPage, total)} of {total.toLocaleString()}
            </p>
            <div className="flex items-center gap-1">
              <Button size="sm" variant="outline" disabled={currentPage <= 1}
                onClick={() => applyFilters({ page: String(currentPage - 1) })}>
                <ArrowLeft className="h-3.5 w-3.5" />
              </Button>
              {Array.from({ length: Math.min(lastPage, 7) }, (_, i) => {
                const page = lastPage <= 7 ? i + 1
                  : currentPage <= 4 ? i + 1
                  : currentPage >= lastPage - 3 ? lastPage - 6 + i
                  : currentPage - 3 + i;
                return (
                  <Button key={page} size="sm" variant={page === currentPage ? 'default' : 'outline'}
                    onClick={() => applyFilters({ page: String(page) })} className="w-8">
                    {page}
                  </Button>
                );
              })}
              <Button size="sm" variant="outline" disabled={currentPage >= lastPage}
                onClick={() => applyFilters({ page: String(currentPage + 1) })}>
                <ArrowRight className="h-3.5 w-3.5" />
              </Button>
            </div>
          </div>
        )}
      </div>

      {/* New Adjustment Dialog */}
      <AdjustmentDialog
        open={newOpen}
        onClose={() => setNewOpen(false)}
        warehouses={warehouses}
        products={products}
        supplies={supplies}
      />

      {/* Approve Confirm Dialog */}
      <Dialog open={approveId !== null} onOpenChange={o => !o && setApproveId(null)}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>Approve Adjustment</DialogTitle>
            <DialogDescription>
              This will immediately update stock levels. This action cannot be undone — it can only be reversed with a counter-adjustment.
            </DialogDescription>
          </DialogHeader>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={() => setApproveId(null)}>Cancel</Button>
            <Button onClick={confirmApprove} disabled={approving} className="bg-emerald-600 hover:bg-emerald-700">
              <CheckCircle className="mr-1.5 h-4 w-4" />
              {approving ? 'Approving…' : 'Yes, Approve'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Reject Dialog */}
      <Dialog open={rejectId !== null} onOpenChange={o => !o && setRejectId(null)}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>Reject Adjustment</DialogTitle>
            <DialogDescription>Provide a reason so the submitter understands why this was rejected.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-1.5">
              <Label>Reason <span className="text-muted-foreground">(optional)</span></Label>
              <Textarea
                rows={3}
                value={rejectReason}
                onChange={e => setRejectReason(e.target.value)}
                placeholder="e.g. Count discrepancy not verified, recount required…"
              />
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setRejectId(null)}>Cancel</Button>
              <Button variant="destructive" onClick={reject}>
                <XCircle className="mr-1.5 h-4 w-4" />Reject
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}

function AdjustmentDialog({ open, onClose, warehouses, products, supplies }: {
  open: boolean; onClose: () => void;
  warehouses: Warehouse[]; products: Product[]; supplies: Supply[];
}) {
  const form = useForm({
    item_type: 'product',
    product_id: '',
    supply_id: '',
    warehouse_id: warehouses[0]?.id ? String(warehouses[0].id) : '',
    quantity_after: 0,
    reason_code: 'CYCLE_COUNT',
    reason_notes: '',
  });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    form.post('/inventory/adjustments', { onSuccess: () => { onClose(); form.reset(); } });
  }

  return (
    <Dialog open={open} onOpenChange={o => !o && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>New Stock Adjustment</DialogTitle>
          <DialogDescription>
            Adjustments are submitted for approval. Stock levels change only after an authorized user approves.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">

          {/* Item type */}
          <div className="space-y-1.5">
            <Label>Item Type</Label>
            <Select value={form.data.item_type} onValueChange={v => {
              form.setData('item_type', v);
              form.setData('product_id', '');
              form.setData('supply_id', '');
            }}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="product">Finished Product</SelectItem>
                <SelectItem value="supply">Raw Material / Supply</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Product or supply picker */}
          {form.data.item_type === 'product' ? (
            <div className="space-y-1.5">
              <Label>Product <span className="text-red-500">*</span></Label>
              <Select value={form.data.product_id || 'none'} onValueChange={v => form.setData('product_id', v === 'none' ? '' : v)}>
                <SelectTrigger><SelectValue placeholder="Select product…" /></SelectTrigger>
                <SelectContent>
                  {products.map(p => (
                    <SelectItem key={p.id} value={String(p.id)}>
                      <span className="font-mono text-xs text-muted-foreground mr-2">{p.sku}</span>{p.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {form.errors.product_id && <p className="text-xs text-red-600">{form.errors.product_id}</p>}
            </div>
          ) : (
            <div className="space-y-1.5">
              <Label>Supply / Material <span className="text-red-500">*</span></Label>
              <Select value={form.data.supply_id || 'none'} onValueChange={v => form.setData('supply_id', v === 'none' ? '' : v)}>
                <SelectTrigger><SelectValue placeholder="Select supply…" /></SelectTrigger>
                <SelectContent>
                  {supplies.map(s => (
                    <SelectItem key={s.id} value={String(s.id)}>
                      <span className="font-mono text-xs text-muted-foreground mr-2">{s.sku}</span>{s.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {form.errors.supply_id && <p className="text-xs text-red-600">{form.errors.supply_id}</p>}
            </div>
          )}

          {/* Warehouse + qty row */}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label>Warehouse <span className="text-red-500">*</span></Label>
              <Select value={form.data.warehouse_id} onValueChange={v => form.setData('warehouse_id', v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {warehouses.map(wh => <SelectItem key={wh.id} value={String(wh.id)}>{wh.name}</SelectItem>)}
                </SelectContent>
              </Select>
              {form.errors.warehouse_id && <p className="text-xs text-red-600">{form.errors.warehouse_id}</p>}
            </div>
            <div className="space-y-1.5">
              <Label>New Physical Qty <span className="text-red-500">*</span></Label>
              <Input
                type="number" min={0}
                value={form.data.quantity_after}
                onChange={e => form.setData('quantity_after', Number(e.target.value))}
                required
              />
              {form.errors.quantity_after && <p className="text-xs text-red-600">{form.errors.quantity_after}</p>}
            </div>
          </div>

          {/* Reason */}
          <div className="space-y-1.5">
            <Label>Reason Code <span className="text-red-500">*</span></Label>
            <Select value={form.data.reason_code} onValueChange={v => form.setData('reason_code', v)}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                {REASON_CODES.map(r => <SelectItem key={r} value={r}>{r.replace(/_/g, ' ')}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label>Notes</Label>
            <Textarea
              rows={2}
              value={form.data.reason_notes}
              onChange={e => form.setData('reason_notes', e.target.value)}
              placeholder="Additional context for this adjustment…"
            />
          </div>

          {/* Info notice */}
          <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-700">
            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>
              This will be <strong>PENDING</strong> until approved. Stock levels only change upon approval.
            </span>
          </div>

          <div className="flex justify-end gap-2 pt-1">
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={form.processing}>
              {form.processing ? 'Submitting…' : 'Submit for Approval'}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function StatusBadge({ status }: { status: string }) {
  const map: Record<string, { cls: string; label: string }> = {
    PENDING:  { cls: 'bg-orange-100 text-orange-700', label: 'Pending' },
    APPROVED: { cls: 'bg-emerald-100 text-emerald-700', label: 'Approved' },
    REJECTED: { cls: 'bg-red-100 text-red-700', label: 'Rejected' },
  };
  const { cls, label } = map[status] ?? { cls: 'bg-gray-100 text-gray-700', label: status };
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${cls}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${
        status === 'PENDING' ? 'bg-orange-500 animate-pulse' : status === 'APPROVED' ? 'bg-emerald-500' : 'bg-red-500'
      }`} />
      {label}
    </span>
  );
}

function StatCard({ label, value, accent }: { label: string; value: number; accent: 'orange' | 'green' | 'gray' }) {
  const borderCls = { orange: 'border-l-orange-500', green: 'border-l-emerald-500', gray: 'border-l-gray-300' }[accent];
  const valueCls  = { orange: 'text-orange-700', green: 'text-emerald-700', gray: '' }[accent];
  return (
    <Card className={`border-l-4 ${borderCls}`}>
      <CardContent className="p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className={`mt-1 text-2xl font-bold tabular-nums ${valueCls}`}>{value}</p>
      </CardContent>
    </Card>
  );
}
