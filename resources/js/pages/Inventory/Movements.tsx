import { useState, useCallback } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { ArrowLeft, ArrowRight, ArrowUpDown, Search, X } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface Movement {
  id: number;
  product?: { id: number; sku: string; name: string };
  warehouse?: { id: number; name: string };
  location?: { id: number; code: string };
  performer?: { id: number; name: string };
  type: string;
  quantity: number;
  notes?: string;
  batch_number?: string;
  created_at: string;
}

interface Props {
  movements: PaginatedResponse<Movement>;
  filters: { type?: string; search?: string; warehouse_id?: string; from?: string; to?: string };
}

const TYPE_LABELS: Record<string, string> = {
  STOCK_IN: 'Stock In', STOCK_OUT: 'Stock Out', ADJUSTMENT: 'Adjustment',
  RETURN: 'Return', RESERVATION: 'Reservation', RELEASE: 'Release',
};

export default function MovementsPage({ movements, filters }: Props) {
  const [type,   setType]   = useState(filters.type   ?? 'all');
  const [search, setSearch] = useState(filters.search ?? '');
  const [from,   setFrom]   = useState(filters.from   ?? '');
  const [to,     setTo]     = useState(filters.to     ?? '');

  const apply = useCallback((overrides: Record<string, string>) => {
    router.get('/inventory/movements', { ...filters, ...overrides }, { preserveState: true, replace: true });
  }, [filters]);

  const hasFilters = type !== 'all' || search || from || to;

  function clearAll() {
    setType('all'); setSearch(''); setFrom(''); setTo('');
    router.get('/inventory/movements', {}, { preserveState: true, replace: true });
  }

  return (
    <AppLayout>
      <Head title="Inventory Movements" />
      <div className="space-y-5 p-6">

        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Inventory Movements</h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Append-only ledger — {movements.total.toLocaleString()} records
            </p>
          </div>
        </div>

        {/* Filter bar */}
        <Card>
          <CardContent className="flex flex-wrap items-end gap-3 p-4">
            {/* Search */}
            <div className="space-y-1 flex-1 min-w-[180px]">
              <label className="text-xs font-medium text-muted-foreground">Search product / SKU</label>
              <div className="relative">
                <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                <Input
                  className="pl-8"
                  placeholder="Search…"
                  value={search}
                  onChange={e => setSearch(e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && apply({ search, page: '1' })}
                />
              </div>
            </div>

            {/* Type */}
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Type</label>
              <Select value={type} onValueChange={v => { setType(v); apply({ type: v === 'all' ? '' : v, page: '1' }); }}>
                <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Types</SelectItem>
                  {Object.entries(TYPE_LABELS).map(([k, v]) => <SelectItem key={k} value={k}>{v}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>

            {/* Date range */}
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">From</label>
              <Input type="date" value={from} onChange={e => setFrom(e.target.value)} className="w-36" />
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">To</label>
              <Input type="date" value={to} onChange={e => setTo(e.target.value)} className="w-36" />
            </div>

            <Button size="sm" onClick={() => apply({ search, from, to, page: '1' })}>Apply</Button>

            {hasFilters && (
              <Button size="sm" variant="ghost" className="gap-1 text-muted-foreground" onClick={clearAll}>
                <X className="h-3.5 w-3.5" /> Clear
              </Button>
            )}
          </CardContent>
        </Card>

        {/* Results count / active filter pills */}
        {hasFilters && (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs text-muted-foreground">Active filters:</span>
            {type !== 'all' && (
              <span className="inline-flex items-center gap-1 rounded-full border bg-white px-2 py-0.5 text-xs font-medium">
                Type: {TYPE_LABELS[type] ?? type}
                <button onClick={() => { setType('all'); apply({ type: '', page: '1' }); }} className="text-muted-foreground hover:text-foreground"><X className="h-3 w-3" /></button>
              </span>
            )}
            {search && (
              <span className="inline-flex items-center gap-1 rounded-full border bg-white px-2 py-0.5 text-xs font-medium">
                Search: {search}
                <button onClick={() => { setSearch(''); apply({ search: '', page: '1' }); }} className="text-muted-foreground hover:text-foreground"><X className="h-3 w-3" /></button>
              </span>
            )}
            {(from || to) && (
              <span className="inline-flex items-center gap-1 rounded-full border bg-white px-2 py-0.5 text-xs font-medium">
                Date: {from || '…'} → {to || '…'}
                <button onClick={() => { setFrom(''); setTo(''); apply({ from: '', to: '', page: '1' }); }} className="text-muted-foreground hover:text-foreground"><X className="h-3 w-3" /></button>
              </span>
            )}
          </div>
        )}

        {/* Table */}
        <Card className="overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent">
                <TableHead className="w-32">When</TableHead>
                <TableHead className="w-28">Type</TableHead>
                <TableHead>Product</TableHead>
                <TableHead>Warehouse</TableHead>
                <TableHead className="w-24">Location</TableHead>
                <TableHead className="w-20 text-right">Qty</TableHead>
                <TableHead className="w-28">Batch</TableHead>
                <TableHead className="w-28">By</TableHead>
                <TableHead>Notes</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {movements.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={9} className="py-16 text-center">
                    <ArrowUpDown className="mx-auto mb-3 h-8 w-8 text-muted-foreground/40" />
                    <p className="font-medium text-muted-foreground">No movements found</p>
                    <p className="mt-1 text-xs text-muted-foreground">Try adjusting your filters or date range.</p>
                    {hasFilters && (
                      <Button variant="link" size="sm" className="mt-2" onClick={clearAll}>Clear all filters</Button>
                    )}
                  </TableCell>
                </TableRow>
              ) : movements.data.map(m => (
                <TableRow key={m.id}>
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">{formatDate(m.created_at)}</TableCell>
                  <TableCell><TypeBadge type={m.type} /></TableCell>
                  <TableCell>
                    {m.product ? (
                      <div>
                        <div className="font-mono text-[11px] text-blue-600">{m.product.sku}</div>
                        <div className="text-sm font-medium leading-tight">{m.product.name}</div>
                      </div>
                    ) : <span className="text-muted-foreground">—</span>}
                  </TableCell>
                  <TableCell className="text-sm">{m.warehouse?.name ?? '—'}</TableCell>
                  <TableCell className="font-mono text-xs">{m.location?.code ?? '—'}</TableCell>
                  <TableCell className={`text-right font-bold tabular-nums ${m.quantity < 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                    {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                  </TableCell>
                  <TableCell className="font-mono text-xs text-muted-foreground">{m.batch_number ?? '—'}</TableCell>
                  <TableCell className="text-sm">{m.performer?.name ?? '—'}</TableCell>
                  <TableCell className="max-w-[200px] truncate text-xs text-muted-foreground">{m.notes ?? ''}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>

        {/* Pagination */}
        {movements.last_page > 1 && (
          <div className="flex items-center justify-between">
            <p className="text-xs text-muted-foreground">
              Showing {((movements.current_page - 1) * movements.per_page) + 1}–{Math.min(movements.current_page * movements.per_page, movements.total)} of {movements.total.toLocaleString()}
            </p>
            <div className="flex items-center gap-1">
              <Button size="sm" variant="outline" disabled={movements.current_page <= 1}
                onClick={() => apply({ page: String(movements.current_page - 1) })}>
                <ArrowLeft className="h-3.5 w-3.5" />
              </Button>
              {Array.from({ length: Math.min(movements.last_page, 7) }, (_, i) => {
                const page = movements.last_page <= 7 ? i + 1
                  : movements.current_page <= 4 ? i + 1
                  : movements.current_page >= movements.last_page - 3 ? movements.last_page - 6 + i
                  : movements.current_page - 3 + i;
                return (
                  <Button key={page} size="sm"
                    variant={page === movements.current_page ? 'default' : 'outline'}
                    onClick={() => apply({ page: String(page) })}
                    className="w-8">
                    {page}
                  </Button>
                );
              })}
              <Button size="sm" variant="outline" disabled={movements.current_page >= movements.last_page}
                onClick={() => apply({ page: String(movements.current_page + 1) })}>
                <ArrowRight className="h-3.5 w-3.5" />
              </Button>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}

function TypeBadge({ type }: { type: string }) {
  const cls: Record<string, string> = {
    STOCK_IN:    'bg-emerald-100 text-emerald-700',
    STOCK_OUT:   'bg-red-100 text-red-700',
    ADJUSTMENT:  'bg-yellow-100 text-yellow-700',
    RETURN:      'bg-blue-100 text-blue-700',
    RESERVATION: 'bg-purple-100 text-purple-700',
    RELEASE:     'bg-indigo-100 text-indigo-700',
  };
  return (
    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold ${cls[type] ?? 'bg-gray-100 text-gray-700'}`}>
      {TYPE_LABELS[type] ?? type}
    </span>
  );
}
