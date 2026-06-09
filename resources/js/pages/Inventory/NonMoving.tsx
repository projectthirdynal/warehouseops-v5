import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { Package, Box, AlertTriangle, ArrowLeft } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';
import { useState } from 'react';

interface NonMovingItem {
  product_id?: number;
  supply_id?: number;
  sku: string;
  item_name: string;
  category: string | null;
  warehouse_id: number | null;
  warehouse_name: string | null;
  current_stock: number;
  reserved_stock: number;
  available_stock: number;
  stock_value: number;
  last_movement_at: string | null;
  last_restock_at: string | null;
  item_type: 'product' | 'supply';
}

interface Props {
  products: PaginatedResponse<NonMovingItem> | null;
  supplies: PaginatedResponse<NonMovingItem> | null;
  total_dead_value: number;
  filters: {
    days: number;
    type: string;
    product_page?: number;
    supply_page?: number;
  };
}

export default function NonMoving({ products, supplies, total_dead_value, filters }: Props) {
  const [days, setDays] = useState(String(filters.days));
  const [type, setType] = useState(filters.type);

  function applyFilters(overrides: Record<string, string>) {
    router.get('/inventory/non-moving', { days, type, ...overrides }, { preserveState: true, replace: true });
  }

  const productData       = products?.data ?? [];
  const supplyData        = supplies?.data ?? [];
  const productPagination = products ?? null;
  const supplyPagination  = supplies ?? null;

  return (
    <AppLayout>
      <Head title="Non-Moving Stock" />
      <div className="space-y-6 p-6">

        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1 flex items-center gap-2">
              <Link href="/inventory" className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                <ArrowLeft className="h-3 w-3" /> Dashboard
              </Link>
            </div>
            <h1 className="text-2xl font-bold tracking-tight">Non-Moving / Dead Stock</h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Stock on hand with no movement in the past <strong>{filters.days}</strong> days.
            </p>
          </div>

          {/* Dead value KPI */}
          <div className="rounded-lg border border-red-200 bg-red-50 px-5 py-3 text-right">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-red-500">Total Dead Value</p>
            <p className="mt-0.5 text-2xl font-bold tabular-nums text-red-700">{formatCurrency(total_dead_value)}</p>
          </div>
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="flex flex-wrap items-end gap-3 p-4">
          <div className="space-y-1">
            <label className="text-xs font-medium text-muted-foreground">Threshold (days)</label>
            <Select value={days} onValueChange={(v) => { setDays(v); applyFilters({ days: v, page: '1' }); }}>
              <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="30">30 days</SelectItem>
                <SelectItem value="60">60 days</SelectItem>
                <SelectItem value="90">90 days</SelectItem>
                <SelectItem value="120">120 days</SelectItem>
                <SelectItem value="180">180 days</SelectItem>
                <SelectItem value="365">1 year</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-muted-foreground">Show</label>
            <Select value={type} onValueChange={(v) => { setType(v); applyFilters({ type: v, page: '1' }); }}>
              <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Products &amp; Materials</SelectItem>
                <SelectItem value="products">Products only</SelectItem>
                <SelectItem value="supplies">Materials only</SelectItem>
              </SelectContent>
            </Select>
          </div>
          </CardContent>
        </Card>

        {/* Products table */}
        {(type === 'all' || type === 'products') && (
          <Card>
            <CardHeader className="flex flex-row items-center gap-2 pb-2">
              <Package className="h-4 w-4 text-muted-foreground" />
              <CardTitle className="text-base">
                Non-Moving Products
                {productPagination && (
                  <span className="ml-2 text-sm font-normal text-muted-foreground">({productPagination.total.toLocaleString()} total)</span>
                )}
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <NonMovingTable rows={productData} emptyLabel="No non-moving products found." />
            </CardContent>
            {productPagination && productPagination.last_page > 1 && (
              <div className="flex justify-center gap-2 border-t p-3">
                <PaginationBar
                  pagination={productPagination}
                  onPage={(p) => applyFilters({ product_page: String(p) })}
                />
              </div>
            )}
          </Card>
        )}

        {/* Supplies table */}
        {(type === 'all' || type === 'supplies') && (
          <Card>
            <CardHeader className="flex flex-row items-center gap-2 pb-2">
              <Box className="h-4 w-4 text-muted-foreground" />
              <CardTitle className="text-base">
                Non-Moving Materials
                {supplyPagination && (
                  <span className="ml-2 text-sm font-normal text-muted-foreground">({supplyPagination.total.toLocaleString()} total)</span>
                )}
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <NonMovingTable rows={supplyData} emptyLabel="No non-moving materials found." />
            </CardContent>
            {supplyPagination && supplyPagination.last_page > 1 && (
              <div className="flex justify-center gap-2 border-t p-3">
                <PaginationBar
                  pagination={supplyPagination}
                  onPage={(p) => applyFilters({ supply_page: String(p) })}
                />
              </div>
            )}
          </Card>
        )}

      </div>
    </AppLayout>
  );
}

function NonMovingTable({ rows, emptyLabel }: { rows: NonMovingItem[]; emptyLabel: string }) {
  return (
    <Table>
      <TableHeader>
        <TableRow className="hover:bg-transparent">
          <TableHead>SKU</TableHead>
          <TableHead>Name</TableHead>
          <TableHead>Category</TableHead>
          <TableHead>Warehouse</TableHead>
          <TableHead className="text-right">On Hand</TableHead>
          <TableHead className="text-right">Available</TableHead>
          <TableHead className="text-right">Value</TableHead>
          <TableHead>Last Movement</TableHead>
          <TableHead>Last Restock</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {rows.length === 0 ? (
          <TableRow>
            <TableCell colSpan={9} className="py-12 text-center text-sm text-muted-foreground">
              {emptyLabel}
            </TableCell>
          </TableRow>
        ) : rows.map((row, i) => {
          const isNeverMoved = row.last_movement_at === null;
          return (
            <TableRow key={`${row.item_type}-${row.product_id ?? row.supply_id}-${i}`}
              className={isNeverMoved ? 'border-l-2 border-red-500 bg-red-50 hover:bg-red-50 dark:bg-red-950/30 dark:hover:bg-red-950/30' : ''}>
              <TableCell className="font-mono text-xs text-muted-foreground">{row.sku}</TableCell>
              <TableCell className="font-medium text-sm max-w-[180px] truncate">{row.item_name}</TableCell>
              <TableCell className="text-sm text-muted-foreground">{row.category ?? '—'}</TableCell>
              <TableCell className="text-sm">{row.warehouse_name ?? '—'}</TableCell>
              <TableCell className="text-right tabular-nums font-medium">{Number(row.current_stock).toLocaleString()}</TableCell>
              <TableCell className="text-right tabular-nums">
                <span className={Number(row.available_stock) <= 0 ? 'text-red-600 font-bold' : ''}>
                  {Number(row.available_stock).toLocaleString()}
                </span>
              </TableCell>
              <TableCell className="text-right tabular-nums text-amber-700 font-medium">
                {formatCurrency(Number(row.stock_value))}
              </TableCell>
              <TableCell className="text-xs text-muted-foreground whitespace-nowrap">
                {isNeverMoved
                  ? <span className="inline-flex items-center gap-1 text-red-600 font-medium"><AlertTriangle className="h-3 w-3" />Never</span>
                  : formatDate(row.last_movement_at!)}
              </TableCell>
              <TableCell className="text-xs text-muted-foreground whitespace-nowrap">
                {row.last_restock_at ? formatDate(row.last_restock_at) : '—'}
              </TableCell>
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}

function PaginationBar({ pagination, onPage }: {
  pagination: PaginatedResponse<NonMovingItem>;
  onPage: (page: number) => void;
}) {
  const { current_page, last_page } = pagination;
  const pages: number[] = [];

  if (last_page <= 7) {
    for (let i = 1; i <= last_page; i++) pages.push(i);
  } else {
    pages.push(1);
    if (current_page > 3) pages.push(-1);
    for (let i = Math.max(2, current_page - 1); i <= Math.min(last_page - 1, current_page + 1); i++) pages.push(i);
    if (current_page < last_page - 2) pages.push(-2);
    pages.push(last_page);
  }

  return (
    <>
      {pages.map((p, idx) =>
        p < 0
          ? <span key={`ellipsis-${idx}`} className="px-1 text-muted-foreground">…</span>
          : <Button key={p} size="sm" variant={p === current_page ? 'default' : 'outline'} onClick={() => onPage(p)}>{p}</Button>
      )}
    </>
  );
}
