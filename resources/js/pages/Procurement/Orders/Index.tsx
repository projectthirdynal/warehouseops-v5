import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { ShoppingCart, Plus, FileEdit, Send, PackageCheck, Package2 } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface PoRow {
  id: number;
  po_number: string;
  status: string;
  expected_delivery_date?: string;
  total_amount: number;
  currency_code: string;
  items_count: number;
  supplier?: { id: number; name: string; code: string };
  warehouse?: { id: number; name: string };
  creator?: { id: number; name: string };
  created_at: string;
}

interface Props {
  orders: PaginatedResponse<PoRow>;
  stats: { draft: number; sent: number; partial: number; received: number };
  suppliers: { id: number; name: string }[];
  filters: { status?: string; supplier?: string; search?: string };
}

const STATUS_COLOR: Record<string, string> = {
  DRAFT:              'bg-gray-100 text-gray-700',
  SENT:               'bg-blue-100 text-blue-700',
  PARTIALLY_RECEIVED: 'bg-yellow-100 text-yellow-800',
  RECEIVED:           'bg-green-100 text-green-700',
  CANCELLED:          'bg-red-100 text-red-700',
};

const STATUS_LABEL: Record<string, string> = {
  PARTIALLY_RECEIVED: 'Partial',
  DRAFT: 'Draft', SENT: 'Sent', RECEIVED: 'Received', CANCELLED: 'Cancelled',
};

export default function PoIndex({ orders, stats, suppliers, filters }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');

  function applyFilters(o: Record<string, string>) {
    router.get('/procurement/orders', { ...filters, ...o }, { preserveState: true, replace: true });
  }

  return (
    <AppLayout>
      <Head title="Purchase Orders" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Purchase Orders</h1>
            <p className="text-sm text-muted-foreground">Orders sent to suppliers.</p>
          </div>
          <Link href="/procurement/orders/create">
            <Button><Plus className="mr-2 h-4 w-4" />New PO</Button>
          </Link>
        </div>

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <StatCard icon={<FileEdit className="h-4 w-4 text-gray-500" />}      label="Draft"    value={stats.draft} />
          <StatCard icon={<Send className="h-4 w-4 text-blue-500" />}          label="Sent"     value={stats.sent} />
          <StatCard icon={<Package2 className="h-4 w-4 text-yellow-600" />}    label="Partial"  value={stats.partial} />
          <StatCard icon={<PackageCheck className="h-4 w-4 text-green-600" />} label="Received" value={stats.received} />
        </div>

        <div className="flex flex-wrap gap-2">
          <form onSubmit={(e) => { e.preventDefault(); applyFilters({ search, page: '1' }); }} className="flex gap-2">
            <Input placeholder="PO # search..." value={search} onChange={e => setSearch(e.target.value)} className="w-56" />
            <Button type="submit" variant="secondary" size="sm">Search</Button>
          </form>
          <Select value={filters.status ?? 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v, page: '1' })}>
            <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Statuses</SelectItem>
              <SelectItem value="DRAFT">Draft</SelectItem>
              <SelectItem value="SENT">Sent</SelectItem>
              <SelectItem value="PARTIALLY_RECEIVED">Partially Received</SelectItem>
              <SelectItem value="RECEIVED">Received</SelectItem>
              <SelectItem value="CANCELLED">Cancelled</SelectItem>
            </SelectContent>
          </Select>
          <Select value={filters.supplier ?? 'all'} onValueChange={(v) => applyFilters({ supplier: v === 'all' ? '' : v, page: '1' })}>
            <SelectTrigger className="w-56"><SelectValue placeholder="Supplier" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Suppliers</SelectItem>
              {suppliers.map(s => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>

        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>PO #</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Supplier</TableHead>
                <TableHead>Warehouse</TableHead>
                <TableHead className="text-right">Items</TableHead>
                <TableHead className="text-right">Total</TableHead>
                <TableHead>Expected</TableHead>
                <TableHead>Created by</TableHead>
                <TableHead>Created</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {orders.data.length === 0 ? (
                <TableRow><TableCell colSpan={9} className="py-12 text-center text-muted-foreground">
                  <ShoppingCart className="mx-auto mb-2 h-8 w-8 opacity-30" />No purchase orders yet.
                </TableCell></TableRow>
              ) : orders.data.map(po => (
                <TableRow key={po.id}>
                  <TableCell>
                    <Link href={`/procurement/orders/${po.id}`} className="font-mono text-sm font-medium text-primary hover:underline">
                      {po.po_number}
                    </Link>
                  </TableCell>
                  <TableCell><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLOR[po.status]}`}>{STATUS_LABEL[po.status] ?? po.status}</span></TableCell>
                  <TableCell className="text-sm">{po.supplier?.name ?? '—'}</TableCell>
                  <TableCell className="text-sm">{po.warehouse?.name ?? '—'}</TableCell>
                  <TableCell className="text-right text-sm">{po.items_count}</TableCell>
                  <TableCell className="text-right font-medium">
                    {po.currency_code === 'PHP' ? '₱' : po.currency_code + ' '}
                    {Number(po.total_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                  </TableCell>
                  <TableCell className="text-sm">{po.expected_delivery_date ? formatDate(po.expected_delivery_date) : '—'}</TableCell>
                  <TableCell className="text-sm">{po.creator?.name ?? '—'}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{formatDate(po.created_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>

        {orders.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: orders.last_page }, (_, i) => i + 1).map(p => (
              <Button key={p} size="sm" variant={p === orders.current_page ? 'default' : 'outline'}
                onClick={() => applyFilters({ page: String(p) })}>{p}</Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}

function StatCard({ icon, label, value }: { icon: React.ReactNode; label: string; value: number }) {
  return (
    <Card>
      <CardHeader className="pb-2"><CardTitle className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</CardTitle></CardHeader>
      <CardContent><div className="flex items-center gap-2">{icon}<span className="text-2xl font-bold">{value}</span></div></CardContent>
    </Card>
  );
}
