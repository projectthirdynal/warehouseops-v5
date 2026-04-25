import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
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
  filters: { type?: string; product_id?: string; warehouse_id?: string; from?: string; to?: string };
}

export default function MovementsPage({ movements, filters }: Props) {
  const [type, setType] = useState(filters.type ?? 'all');
  const [from, setFrom] = useState(filters.from ?? '');
  const [to,   setTo]   = useState(filters.to ?? '');

  function applyFilters(overrides: Record<string, string>) {
    router.get('/inventory/movements', { ...filters, ...overrides }, { preserveState: true, replace: true });
  }

  return (
    <AppLayout>
      <Head title="Inventory Movements" />
      <div className="space-y-6 p-6">
        <div>
          <h1 className="text-2xl font-bold">Inventory Movements</h1>
          <p className="text-sm text-muted-foreground">Append-only movement ledger across all warehouses.</p>
        </div>

        <Card className="p-4 flex flex-wrap items-end gap-3">
          <div className="space-y-1">
            <label className="text-xs font-medium text-muted-foreground">Type</label>
            <Select value={type} onValueChange={(v) => { setType(v); applyFilters({ type: v === 'all' ? '' : v, page: '1' }); }}>
              <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Types</SelectItem>
                <SelectItem value="STOCK_IN">Stock In</SelectItem>
                <SelectItem value="STOCK_OUT">Stock Out</SelectItem>
                <SelectItem value="ADJUSTMENT">Adjustment</SelectItem>
                <SelectItem value="RETURN">Return</SelectItem>
                <SelectItem value="RESERVATION">Reservation</SelectItem>
                <SelectItem value="RELEASE">Release</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-muted-foreground">From</label>
            <Input type="date" value={from} onChange={e => setFrom(e.target.value)} className="w-44" />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-muted-foreground">To</label>
            <Input type="date" value={to} onChange={e => setTo(e.target.value)} className="w-44" />
          </div>
          <Button variant="secondary" size="sm" onClick={() => applyFilters({ from, to, page: '1' })}>Apply</Button>
        </Card>

        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>When</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Product</TableHead>
                <TableHead>Warehouse</TableHead>
                <TableHead>Location</TableHead>
                <TableHead className="text-right">Qty</TableHead>
                <TableHead>Batch</TableHead>
                <TableHead>By</TableHead>
                <TableHead>Notes</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {movements.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={9} className="py-12 text-center text-muted-foreground">No movements match these filters.</TableCell>
                </TableRow>
              ) : movements.data.map(m => (
                <TableRow key={m.id}>
                  <TableCell className="text-xs text-muted-foreground whitespace-nowrap">{formatDate(m.created_at)}</TableCell>
                  <TableCell>
                    <span className={`rounded px-2 py-0.5 text-xs font-medium ${typeStyle(m.type)}`}>{m.type}</span>
                  </TableCell>
                  <TableCell className="text-sm">
                    {m.product ? (<><span className="font-mono">{m.product.sku}</span> — {m.product.name}</>) : '—'}
                  </TableCell>
                  <TableCell className="text-sm">{m.warehouse?.name ?? '—'}</TableCell>
                  <TableCell className="text-sm font-mono">{m.location?.code ?? '—'}</TableCell>
                  <TableCell className={`text-right font-medium ${m.quantity < 0 ? 'text-red-600' : 'text-green-600'}`}>
                    {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                  </TableCell>
                  <TableCell className="text-xs font-mono">{m.batch_number ?? '—'}</TableCell>
                  <TableCell className="text-sm">{m.performer?.name ?? '—'}</TableCell>
                  <TableCell className="text-xs text-muted-foreground max-w-xs truncate">{m.notes ?? ''}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>

        {movements.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: movements.last_page }, (_, i) => i + 1).map(p => (
              <Button key={p} size="sm" variant={p === movements.current_page ? 'default' : 'outline'}
                onClick={() => applyFilters({ page: String(p) })}>{p}</Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}

function typeStyle(type: string): string {
  switch (type) {
    case 'STOCK_IN':    return 'bg-green-100 text-green-700';
    case 'STOCK_OUT':   return 'bg-red-100 text-red-700';
    case 'ADJUSTMENT':  return 'bg-yellow-100 text-yellow-700';
    case 'RETURN':      return 'bg-blue-100 text-blue-700';
    case 'RESERVATION': return 'bg-purple-100 text-purple-700';
    case 'RELEASE':     return 'bg-indigo-100 text-indigo-700';
    default:            return 'bg-gray-100 text-gray-700';
  }
}
