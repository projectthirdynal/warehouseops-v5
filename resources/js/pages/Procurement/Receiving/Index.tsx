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
import { PackageCheck, PackagePlus, Truck } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface GrnRow {
  id: number;
  grn_number: string;
  status: string;
  received_at: string;
  items_count: number;
  warehouse?: { id: number; name: string };
  receiver?: { id: number; name: string };
  purchase_order?: { id: number; po_number: string; supplier?: { id: number; name: string } };
}

interface OpenPo {
  id: number;
  po_number: string;
  status: string;
  expected_delivery_date?: string;
  supplier?: { id: number; name: string };
}

interface Props {
  grns: PaginatedResponse<GrnRow>;
  open_pos: OpenPo[];
  filters: { status?: string; search?: string };
}

export default function ReceivingIndex({ grns, open_pos, filters }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');

  function applyFilters(o: Record<string, string>) {
    router.get('/procurement/receiving', { ...filters, ...o }, { preserveState: true, replace: true });
  }

  function startReceive(poId: number) {
    router.get('/procurement/receiving/create', { po_id: poId });
  }

  return (
    <AppLayout>
      <Head title="Receiving" />
      <div className="space-y-6 p-6">
        <div>
          <h1 className="text-2xl font-bold">Receiving (GRN)</h1>
          <p className="text-sm text-muted-foreground">Receive items into the warehouse against open purchase orders.</p>
        </div>

        {/* Open POs to receive */}
        <Card>
          <CardHeader><CardTitle className="text-base flex items-center gap-2"><Truck className="h-4 w-4" />Open Purchase Orders ({open_pos.length})</CardTitle></CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>PO #</TableHead>
                  <TableHead>Supplier</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Expected</TableHead>
                  <TableHead></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {open_pos.length === 0 ? (
                  <TableRow><TableCell colSpan={5} className="py-8 text-center text-sm text-muted-foreground">No open POs awaiting receipt.</TableCell></TableRow>
                ) : open_pos.map(po => (
                  <TableRow key={po.id}>
                    <TableCell><Link href={`/procurement/orders/${po.id}`} className="font-mono text-sm hover:underline">{po.po_number}</Link></TableCell>
                    <TableCell className="text-sm">{po.supplier?.name ?? '—'}</TableCell>
                    <TableCell><span className={`rounded-full px-2 py-0.5 text-xs ${po.status === 'PARTIALLY_RECEIVED' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-700'}`}>{po.status.replace('_', ' ')}</span></TableCell>
                    <TableCell className="text-sm">{po.expected_delivery_date ? formatDate(po.expected_delivery_date) : '—'}</TableCell>
                    <TableCell>
                      <Button size="sm" onClick={() => startReceive(po.id)}>
                        <PackagePlus className="mr-1 h-3.5 w-3.5" />Receive
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* GRN history */}
        <div className="flex flex-wrap gap-2">
          <form onSubmit={(e) => { e.preventDefault(); applyFilters({ search, page: '1' }); }} className="flex gap-2">
            <Input placeholder="GRN # search..." value={search} onChange={e => setSearch(e.target.value)} className="w-56" />
            <Button type="submit" variant="secondary" size="sm">Search</Button>
          </form>
          <Select value={filters.status ?? 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v, page: '1' })}>
            <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Statuses</SelectItem>
              <SelectItem value="DRAFT">Draft</SelectItem>
              <SelectItem value="CONFIRMED">Confirmed</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <Card>
          <CardHeader><CardTitle className="text-base">GRN History</CardTitle></CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>GRN #</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>PO #</TableHead>
                  <TableHead>Supplier</TableHead>
                  <TableHead>Warehouse</TableHead>
                  <TableHead>Received At</TableHead>
                  <TableHead>Received By</TableHead>
                  <TableHead className="text-right">Items</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {grns.data.length === 0 ? (
                  <TableRow><TableCell colSpan={8} className="py-12 text-center text-muted-foreground">
                    <PackageCheck className="mx-auto mb-2 h-8 w-8 opacity-30" />No GRNs recorded yet.
                  </TableCell></TableRow>
                ) : grns.data.map(g => (
                  <TableRow key={g.id}>
                    <TableCell><Link href={`/procurement/receiving/${g.id}`} className="font-mono text-sm font-medium text-primary hover:underline">{g.grn_number}</Link></TableCell>
                    <TableCell><span className={`rounded-full px-2 py-0.5 text-xs ${g.status === 'CONFIRMED' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'}`}>{g.status}</span></TableCell>
                    <TableCell><Link href={`/procurement/orders/${g.purchase_order?.id}`} className="font-mono text-sm hover:underline">{g.purchase_order?.po_number}</Link></TableCell>
                    <TableCell className="text-sm">{g.purchase_order?.supplier?.name ?? '—'}</TableCell>
                    <TableCell className="text-sm">{g.warehouse?.name ?? '—'}</TableCell>
                    <TableCell className="text-sm">{formatDate(g.received_at)}</TableCell>
                    <TableCell className="text-sm">{g.receiver?.name ?? '—'}</TableCell>
                    <TableCell className="text-right text-sm">{g.items_count}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {grns.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: grns.last_page }, (_, i) => i + 1).map(p => (
              <Button key={p} size="sm" variant={p === grns.current_page ? 'default' : 'outline'}
                onClick={() => applyFilters({ page: String(p) })}>{p}</Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
