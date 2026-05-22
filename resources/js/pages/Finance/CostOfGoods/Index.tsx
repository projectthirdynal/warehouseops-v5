import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { TrendingDown, Package, FileText } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface CogsRow {
  id: number;
  product?: { id: number; sku: string; name: string };
  cost_lot?: { id: number; batch_number?: string; expiry_date?: string; received_at: string };
  waybill_id?: number;
  method: string;
  quantity: number;
  unit_cost: number;
  total_cost: number;
  currency_code: string;
  recorded_at: string;
  synced_to_qbo_at?: string;
}

interface ByProduct {
  id: number;
  sku: string;
  name: string;
  total_qty: number;
  total_cost: number;
}

interface Props {
  entries: PaginatedResponse<CogsRow>;
  totals: { total_cost: number; total_quantity: number; entries_count: number };
  by_product: ByProduct[];
  filters: { from?: string; to?: string; product_id?: string };
}

export default function CogsIndex({ entries, totals, by_product, filters }: Props) {
  const [from, setFrom] = useState(filters.from ?? '');
  const [to,   setTo]   = useState(filters.to ?? '');

  function applyFilters(o: Record<string, string>) {
    router.get('/finance/cost-of-goods', { ...filters, ...o }, { preserveState: true, replace: true });
  }

  return (
    <AppLayout>
      <Head title="Cost of Goods Sold" />
      <div className="space-y-6 p-6">
        <div>
          <h1 className="text-2xl font-bold">Cost of Goods Sold</h1>
          <p className="text-sm text-muted-foreground">FIFO lot consumption ledger — every sale traces to its source cost lot.</p>
        </div>

        {/* Date filter */}
        <Card className="flex flex-wrap items-end gap-3 p-4">
          <div className="space-y-1"><label className="text-xs font-medium text-muted-foreground">From</label>
            <Input type="date" value={from} onChange={e => setFrom(e.target.value)} className="w-44" /></div>
          <div className="space-y-1"><label className="text-xs font-medium text-muted-foreground">To</label>
            <Input type="date" value={to} onChange={e => setTo(e.target.value)} className="w-44" /></div>
          <Button variant="secondary" size="sm" onClick={() => applyFilters({ from, to, page: '1' })}>Apply</Button>
        </Card>

        {/* Totals */}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-xs uppercase tracking-wide text-muted-foreground">Total COGS</CardTitle></CardHeader>
            <CardContent><div className="flex items-center gap-2"><TrendingDown className="h-5 w-5 text-red-500" />
              <span className="text-2xl font-bold">₱{totals.total_cost.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span></div></CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-xs uppercase tracking-wide text-muted-foreground">Units Sold</CardTitle></CardHeader>
            <CardContent><div className="flex items-center gap-2"><Package className="h-5 w-5 text-blue-500" />
              <span className="text-2xl font-bold">{Number(totals.total_quantity).toLocaleString()}</span></div></CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-xs uppercase tracking-wide text-muted-foreground">Lot Consumptions</CardTitle></CardHeader>
            <CardContent><div className="flex items-center gap-2"><FileText className="h-5 w-5 text-purple-500" />
              <span className="text-2xl font-bold">{totals.entries_count}</span></div></CardContent>
          </Card>
        </div>

        <div className="grid gap-4 lg:grid-cols-3">
          <Card className="lg:col-span-1">
            <CardHeader className="pb-2"><CardTitle className="text-base">Top by Cost</CardTitle></CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Product</TableHead>
                    <TableHead className="text-right">Qty</TableHead>
                    <TableHead className="text-right">Cost</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {by_product.length === 0 ? (
                    <TableRow><TableCell colSpan={3} className="py-8 text-center text-sm text-muted-foreground">No COGS recorded.</TableCell></TableRow>
                  ) : by_product.map(p => (
                    <TableRow key={p.id}>
                      <TableCell className="text-sm">
                        <span className="font-mono">{p.sku}</span>
                        <p className="text-xs text-muted-foreground">{p.name}</p>
                      </TableCell>
                      <TableCell className="text-right text-sm">{Number(p.total_qty).toLocaleString()}</TableCell>
                      <TableCell className="text-right font-medium">₱{Number(p.total_cost).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card className="lg:col-span-2">
            <CardHeader className="pb-2"><CardTitle className="text-base">Lot Consumption Log</CardTitle></CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>When</TableHead>
                    <TableHead>Product</TableHead>
                    <TableHead>Batch</TableHead>
                    <TableHead className="text-right">Qty</TableHead>
                    <TableHead className="text-right">Unit Cost</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead>Waybill</TableHead>
                    <TableHead>QBO</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {entries.data.length === 0 ? (
                    <TableRow><TableCell colSpan={8} className="py-12 text-center text-muted-foreground">No COGS entries in this range.</TableCell></TableRow>
                  ) : entries.data.map(e => (
                    <TableRow key={e.id}>
                      <TableCell className="text-xs text-muted-foreground whitespace-nowrap">{formatDate(e.recorded_at)}</TableCell>
                      <TableCell className="text-sm">
                        {e.product ? <><span className="font-mono">{e.product.sku}</span> — {e.product.name}</> : '—'}
                      </TableCell>
                      <TableCell className="font-mono text-xs">{e.cost_lot?.batch_number ?? '—'}</TableCell>
                      <TableCell className="text-right text-sm">{Number(e.quantity).toLocaleString()}</TableCell>
                      <TableCell className="text-right text-sm">{e.currency_code === 'PHP' ? '₱' : e.currency_code + ' '}{Number(e.unit_cost).toLocaleString('en-PH', { minimumFractionDigits: 4 })}</TableCell>
                      <TableCell className="text-right font-medium">{e.currency_code === 'PHP' ? '₱' : e.currency_code + ' '}{Number(e.total_cost).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</TableCell>
                      <TableCell className="text-xs">{e.waybill_id ? `#${e.waybill_id}` : '—'}</TableCell>
                      <TableCell><span className={`rounded-full px-2 py-0.5 text-xs ${e.synced_to_qbo_at ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>{e.synced_to_qbo_at ? 'Synced' : 'Pending'}</span></TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>

        {entries.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: entries.last_page }, (_, i) => i + 1).map(p => (
              <Button key={p} size="sm" variant={p === entries.current_page ? 'default' : 'outline'}
                onClick={() => applyFilters({ page: String(p) })}>{p}</Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
