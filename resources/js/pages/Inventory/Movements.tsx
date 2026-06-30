import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
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
import { ArrowLeft, Package, Box } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';
import Paginator from '@/components/Paginator';

interface MovementRow {
  id: number;
  stream: 'product' | 'material';
  type: string;
  quantity: number;
  batch_number?: string | null;
  notes?: string | null;
  created_at: string;
  item?: { id: number; sku: string; name: string } | null;
  location_code?: string | null;
  warehouse?: { id: number; name: string } | null;
  performer?: { id: number; name: string } | null;
}

interface Props {
  movements: PaginatedResponse<MovementRow>;
  filters: { stream?: string; type?: string; warehouse_id?: string; from?: string; to?: string };
}

const TYPE_LABELS: Record<string, string> = {
  STOCK_IN: 'Stock In',
  STOCK_OUT: 'Stock Out',
  ADJUSTMENT: 'Adjustment',
  RETURN: 'Return',
  RESERVATION: 'Reservation',
  RELEASE: 'Release',
  TRANSFER: 'Transfer',
  WRITE_OFF: 'Write-Off',
};

const TYPE_STYLES: Record<string, string> = {
  STOCK_IN: 'bg-success/10 text-success',
  STOCK_OUT: 'bg-destructive/10 text-destructive',
  ADJUSTMENT: 'bg-warning/10 text-warning',
  RETURN: 'bg-info/10 text-info',
  RESERVATION: 'bg-primary/10 text-primary',
  RELEASE: 'bg-primary/10 text-primary',
  TRANSFER: 'bg-info/10 text-info',
  WRITE_OFF: 'bg-destructive/10 text-destructive',
};

export default function MovementsPage({ movements, filters }: Props) {
  const stream = filters.stream ?? 'products';
  const [from, setFrom] = useState(filters.from ?? '');
  const [to, setTo] = useState(filters.to ?? '');

  function applyFilters(overrides: Record<string, string>) {
    router.get(
      '/inventory/movements',
      { ...filters, ...overrides },
      { preserveState: true, replace: true }
    );
  }

  function setStream(s: string) {
    applyFilters({ stream: s, type: '', page: '1' });
  }

  const isProducts = stream === 'products';
  const isMaterials = stream === 'materials';

  return (
    <AppLayout>
      <Head title="Inventory Movements" />
      <div className="space-y-4 p-4 sm:space-y-6 sm:p-6">
        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1 flex items-center gap-2">
              <Link
                href="/inventory"
                className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
              >
                <ArrowLeft className="h-3 w-3" /> Dashboard
              </Link>
            </div>
            <h1 className="text-2xl font-bold tracking-tight">Inventory Movements</h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Append-only movement ledger for all stock changes across warehouses.
            </p>
          </div>
          {/* Stream toggle */}
          <div className="flex rounded-md border p-1 gap-1">
            <button
              type="button"
              onClick={() => setStream('products')}
              className={`flex items-center gap-1.5 rounded px-3 py-1.5 text-sm font-medium transition-colors ${
                isProducts
                  ? 'bg-foreground text-background'
                  : 'text-muted-foreground hover:text-foreground'
              }`}
            >
              <Package className="h-3.5 w-3.5" /> Products
            </button>
            <button
              type="button"
              onClick={() => setStream('materials')}
              className={`flex items-center gap-1.5 rounded px-3 py-1.5 text-sm font-medium transition-colors ${
                isMaterials
                  ? 'bg-foreground text-background'
                  : 'text-muted-foreground hover:text-foreground'
              }`}
            >
              <Box className="h-3.5 w-3.5" /> Materials
            </button>
          </div>
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="flex flex-wrap items-end gap-3 p-4">
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Type</label>
              <Select
                value={filters.type ?? 'all'}
                onValueChange={(v) => applyFilters({ type: v === 'all' ? '' : v, page: '1' })}
              >
                <SelectTrigger className="w-44">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Types</SelectItem>
                  <SelectItem value="STOCK_IN">Stock In</SelectItem>
                  <SelectItem value="STOCK_OUT">Stock Out</SelectItem>
                  <SelectItem value="ADJUSTMENT">Adjustment</SelectItem>
                  <SelectItem value="RETURN">Return</SelectItem>
                  {isProducts && <SelectItem value="RESERVATION">Reservation</SelectItem>}
                  {isProducts && <SelectItem value="RELEASE">Release</SelectItem>}
                  {isProducts && <SelectItem value="TRANSFER">Transfer</SelectItem>}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">From</label>
              <Input
                type="date"
                value={from}
                onChange={(e) => setFrom(e.target.value)}
                className="w-36"
              />
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">To</label>
              <Input
                type="date"
                value={to}
                onChange={(e) => setTo(e.target.value)}
                className="w-36"
              />
            </div>
            <Button
              variant="secondary"
              size="sm"
              onClick={() => applyFilters({ from, to, page: '1' })}
            >
              Apply
            </Button>
            {(filters.type || filters.from || filters.to) && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  setFrom('');
                  setTo('');
                  applyFilters({ type: '', from: '', to: '', page: '1' });
                }}
              >
                Clear
              </Button>
            )}
          </CardContent>
        </Card>

        {/* Table */}
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>When</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>{isProducts ? 'Product' : 'Material'}</TableHead>
                <TableHead>Warehouse</TableHead>
                {isProducts && <TableHead>Location</TableHead>}
                <TableHead className="text-right">Qty</TableHead>
                <TableHead>Batch</TableHead>
                <TableHead>By</TableHead>
                <TableHead>Notes</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {movements.data.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={isProducts ? 9 : 8}
                    className="py-14 text-center text-muted-foreground"
                  >
                    <div className="flex flex-col items-center gap-2">
                      {isProducts ? (
                        <Package className="h-8 w-8 opacity-20" />
                      ) : (
                        <Box className="h-8 w-8 opacity-20" />
                      )}
                      <span>
                        No {isProducts ? 'product' : 'material'} movements match these filters.
                      </span>
                    </div>
                  </TableCell>
                </TableRow>
              ) : (
                movements.data.map((m) => (
                  <TableRow key={`${m.stream}-${m.id}`}>
                    <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                      {formatDate(m.created_at)}
                    </TableCell>
                    <TableCell>
                      <span
                        className={`rounded px-2 py-0.5 text-xs font-medium ${TYPE_STYLES[m.type] ?? 'bg-muted text-muted-foreground'}`}
                      >
                        {TYPE_LABELS[m.type] ?? m.type}
                      </span>
                    </TableCell>
                    <TableCell className="text-sm">
                      {m.item ? (
                        <>
                          <span className="font-mono text-xs text-muted-foreground">
                            {m.item.sku}
                          </span>{' '}
                          <span className="ml-1">{m.item.name}</span>
                        </>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </TableCell>
                    <TableCell className="text-sm">{m.warehouse?.name ?? '—'}</TableCell>
                    {isProducts && (
                      <TableCell className="font-mono text-xs text-muted-foreground">
                        {m.location_code ?? '—'}
                      </TableCell>
                    )}
                    <TableCell
                      className={`text-right font-semibold tabular-nums ${m.quantity < 0 ? 'text-destructive' : 'text-success'}`}
                    >
                      {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                    </TableCell>
                    <TableCell className="font-mono text-xs text-muted-foreground">
                      {m.batch_number ?? '—'}
                    </TableCell>
                    <TableCell className="text-sm">{m.performer?.name ?? '—'}</TableCell>
                    <TableCell className="max-w-xs truncate text-xs text-muted-foreground">
                      {m.notes ?? ''}
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
          {movements.last_page > 1 && (
            <div className="border-t p-3">
              <Paginator
                pagination={movements}
                url="/inventory/movements"
                params={filters as Record<string, string>}
              />
            </div>
          )}
        </Card>
      </div>
    </AppLayout>
  );
}
