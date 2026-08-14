import { useState } from 'react';
import { toast } from 'sonner';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog';
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
import { ArrowLeft, ArrowRightLeft, CheckCircle, Plus, Trash2, XCircle } from 'lucide-react';
import Paginator from '@/components/Paginator';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface StockableItem {
  id: number;
  name: string;
  sku: string;
}

interface Warehouse {
  id: number;
  name: string;
  code: string;
}

interface TransferRow {
  id: number;
  stockable: StockableItem | null;
  from_warehouse: Warehouse | null;
  to_warehouse: Warehouse | null;
  quantity: number;
  status: string;
  reason_notes?: string | null;
  requested_by_name?: string | null;
  approved_by_name?: string | null;
  approved_at?: string | null;
  created_at: string;
}

interface Props {
  transfers: PaginatedResponse<TransferRow>;
  stats: { pending: number; completed: number; rejected: number; cancelled: number };
  warehouses: Warehouse[];
  products: StockableItem[];
  supplies: StockableItem[];
  filters: { status?: string; warehouse_id?: string; stockable_type?: string };
  auth: { user: { role: string } };
}

const statusColors: Record<string, string> = {
  PENDING: 'bg-warning text-white',
  COMPLETED: 'bg-success text-white',
  REJECTED: 'bg-destructive text-white',
  CANCELLED: 'bg-muted text-white',
};

export default function StockTransfers({
  transfers,
  stats,
  warehouses,
  products,
  supplies,
  filters,
  auth,
}: Props) {
  const [newOpen, setNewOpen] = useState(false);
  const [rejectId, setRejectId] = useState<number | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  const canApprove = ['superadmin', 'admin', 'supervisor', 'warehouse'].includes(auth.user.role);

  const { data, setData, post, processing, errors, reset } = useForm({
    stockable_type: 'product',
    stockable_id: '',
    from_warehouse_id: '',
    to_warehouse_id: '',
    quantity: '',
    reason_notes: '',
  });

  function applyFilters(overrides: Record<string, string>) {
    router.get(
      '/inventory/transfers',
      { ...filters, ...overrides },
      { preserveState: true, replace: true }
    );
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    post('/inventory/transfers', {
      onSuccess: () => {
        toast.success('Transfer request submitted.');
        setNewOpen(false);
        reset();
      },
      onError: () => toast.error('Failed to submit transfer request.'),
      preserveScroll: true,
    });
  }

  function approve(id: number) {
    router.post(
      `/inventory/transfers/${id}/approve`,
      {},
      {
        onSuccess: () => toast.success('Transfer approved and stock moved.'),
        onError: (err) => toast.error(err?.message ?? 'Failed to approve transfer.'),
        preserveScroll: true,
      }
    );
  }

  function reject() {
    if (!rejectId) return;
    router.post(
      `/inventory/transfers/${rejectId}/reject`,
      { reason: rejectReason },
      {
        onSuccess: () => {
          toast.success('Transfer rejected.');
          setRejectId(null);
          setRejectReason('');
        },
        onError: () => toast.error('Failed to reject transfer.'),
        preserveScroll: true,
      }
    );
  }

  function cancel(id: number) {
    router.post(
      `/inventory/transfers/${id}/cancel`,
      {},
      {
        onSuccess: () => toast.success('Transfer cancelled.'),
        onError: () => toast.error('Failed to cancel transfer.'),
        preserveScroll: true,
      }
    );
  }

  const items = data.stockable_type === 'product' ? products : supplies;

  return (
    <AppLayout>
      <Head title="Stock Transfers" />
      <div className="space-y-5 p-6">
        {/* Header */}
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1 flex items-center gap-1 text-xs text-muted-foreground">
              <Link href="/inventory" className="flex items-center gap-1 hover:text-foreground">
                <ArrowLeft className="h-3 w-3" /> Dashboard
              </Link>
            </div>
            <h1 className="text-2xl font-bold tracking-tight flex items-center gap-2">
              <ArrowRightLeft className="h-5 w-5 text-primary" />
              Multi-Warehouse Stock Transfers
            </h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Request, approve, and track stock transfers between warehouses.
            </p>
          </div>
          <Button onClick={() => setNewOpen(true)}>
            <Plus className="mr-1.5 h-4 w-4" />
            New Transfer Request
          </Button>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-4 gap-4">
          <StatCard label="Pending" value={stats.pending} accent="warning" />
          <StatCard label="Completed" value={stats.completed} accent="success" />
          <StatCard label="Rejected" value={stats.rejected} accent="destructive" />
          <StatCard label="Cancelled" value={stats.cancelled} accent="neutral" />
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="flex flex-wrap items-end gap-3 p-4">
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Status</label>
              <Select
                value={filters.status ?? 'all'}
                onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v, page: '1' })}
              >
                <SelectTrigger className="w-36">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Statuses</SelectItem>
                  <SelectItem value="PENDING">Pending</SelectItem>
                  <SelectItem value="COMPLETED">Completed</SelectItem>
                  <SelectItem value="REJECTED">Rejected</SelectItem>
                  <SelectItem value="CANCELLED">Cancelled</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Warehouse</label>
              <Select
                value={filters.warehouse_id ?? 'all'}
                onValueChange={(v) =>
                  applyFilters({ warehouse_id: v === 'all' ? '' : v, page: '1' })
                }
              >
                <SelectTrigger className="w-44">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Warehouses</SelectItem>
                  {warehouses.map((w) => (
                    <SelectItem key={w.id} value={String(w.id)}>
                      {w.name} ({w.code})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Type</label>
              <Select
                value={filters.stockable_type ?? 'all'}
                onValueChange={(v) =>
                  applyFilters({ stockable_type: v === 'all' ? '' : v, page: '1' })
                }
              >
                <SelectTrigger className="w-32">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Types</SelectItem>
                  <SelectItem value="product">Products</SelectItem>
                  <SelectItem value="supply">Supplies</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>

        {/* Table */}
        <Card>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>ID</TableHead>
                  <TableHead>Item</TableHead>
                  <TableHead>From → To</TableHead>
                  <TableHead className="text-right">Qty</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Requested By</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {transfers.data.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell className="text-muted-foreground">#{row.id}</TableCell>
                    <TableCell>
                      <div className="font-medium">{row.stockable?.name ?? 'Unknown'}</div>
                      <div className="text-xs text-muted-foreground">
                        {row.stockable?.sku ?? '—'}
                      </div>
                    </TableCell>
                    <TableCell>
                      {row.from_warehouse?.code ?? '—'} → {row.to_warehouse?.code ?? '—'}
                    </TableCell>
                    <TableCell className="text-right">{row.quantity}</TableCell>
                    <TableCell>
                      <Badge className={statusColors[row.status] ?? 'bg-muted'}>{row.status}</Badge>
                    </TableCell>
                    <TableCell>{row.requested_by_name ?? '—'}</TableCell>
                    <TableCell>{formatDate(row.created_at)}</TableCell>
                    <TableCell className="text-right">
                      {row.status === 'PENDING' && canApprove && (
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="outline" onClick={() => approve(row.id)}>
                            <CheckCircle className="mr-1 h-3.5 w-3.5 text-success" />
                            Approve
                          </Button>
                          <Button size="sm" variant="outline" onClick={() => setRejectId(row.id)}>
                            <XCircle className="mr-1 h-3.5 w-3.5 text-destructive" />
                            Reject
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => cancel(row.id)}>
                            <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
                          </Button>
                        </div>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
                {transfers.data.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                      No transfer requests found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
            <div className="border-t p-4">
              <Paginator pagination={transfers} />
            </div>
          </CardContent>
        </Card>
      </div>

      {/* New transfer modal */}
      <Dialog open={newOpen} onOpenChange={setNewOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>New Transfer Request</DialogTitle>
            <DialogDescription>
              Move stock from one warehouse to another. Requires approval.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={submit} className="space-y-4 pt-2">
            <div className="space-y-1">
              <Label htmlFor="stockable_type">Type</Label>
              <Select
                value={data.stockable_type}
                onValueChange={(v) => {
                  setData('stockable_type', v);
                  setData('stockable_id', '');
                }}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="product">Product</SelectItem>
                  <SelectItem value="supply">Supply</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1">
              <Label htmlFor="stockable_id">Item</Label>
              <Select value={data.stockable_id} onValueChange={(v) => setData('stockable_id', v)}>
                <SelectTrigger>
                  <SelectValue placeholder="Select item" />
                </SelectTrigger>
                <SelectContent>
                  {items.map((item) => (
                    <SelectItem key={item.id} value={String(item.id)}>
                      {item.name} ({item.sku})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.stockable_id && (
                <p className="text-xs text-destructive">{errors.stockable_id}</p>
              )}
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label htmlFor="from_warehouse_id">From Warehouse</Label>
                <Select
                  value={data.from_warehouse_id}
                  onValueChange={(v) => setData('from_warehouse_id', v)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Source" />
                  </SelectTrigger>
                  <SelectContent>
                    {warehouses.map((w) => (
                      <SelectItem key={w.id} value={String(w.id)}>
                        {w.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {errors.from_warehouse_id && (
                  <p className="text-xs text-destructive">{errors.from_warehouse_id}</p>
                )}
              </div>

              <div className="space-y-1">
                <Label htmlFor="to_warehouse_id">To Warehouse</Label>
                <Select
                  value={data.to_warehouse_id}
                  onValueChange={(v) => setData('to_warehouse_id', v)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Destination" />
                  </SelectTrigger>
                  <SelectContent>
                    {warehouses.map((w) => (
                      <SelectItem key={w.id} value={String(w.id)}>
                        {w.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {errors.to_warehouse_id && (
                  <p className="text-xs text-destructive">{errors.to_warehouse_id}</p>
                )}
              </div>
            </div>

            <div className="space-y-1">
              <Label htmlFor="quantity">Quantity</Label>
              <Input
                id="quantity"
                type="number"
                min={1}
                value={data.quantity}
                onChange={(e) => setData('quantity', e.target.value)}
                placeholder="Transfer quantity"
              />
              {errors.quantity && <p className="text-xs text-destructive">{errors.quantity}</p>}
            </div>

            <div className="space-y-1">
              <Label htmlFor="reason_notes">Notes</Label>
              <Textarea
                id="reason_notes"
                value={data.reason_notes}
                onChange={(e) => setData('reason_notes', e.target.value)}
                placeholder="Reason / reference"
              />
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => setNewOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={processing}>
                Submit Request
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* Reject modal */}
      <Dialog open={!!rejectId} onOpenChange={(open) => !open && setRejectId(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Reject Transfer</DialogTitle>
            <DialogDescription>Provide a reason for rejection.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4 pt-2">
            <Textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              placeholder="Rejection reason"
            />
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setRejectId(null)}>
                Cancel
              </Button>
              <Button variant="destructive" onClick={reject}>
                Reject
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}

function StatCard({
  label,
  value,
  accent,
}: {
  label: string;
  value: number;
  accent: 'warning' | 'success' | 'destructive' | 'neutral';
}) {
  const accentClass = {
    warning: 'bg-warning/10 text-warning',
    success: 'bg-success/10 text-success',
    destructive: 'bg-destructive/10 text-destructive',
    neutral: 'bg-muted text-muted-foreground',
  }[accent];

  return (
    <Card>
      <CardContent className="p-4">
        <div className="text-xs font-medium text-muted-foreground uppercase">{label}</div>
        <div
          className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-2xl font-bold ${accentClass}`}
        >
          {value}
        </div>
      </CardContent>
    </Card>
  );
}
