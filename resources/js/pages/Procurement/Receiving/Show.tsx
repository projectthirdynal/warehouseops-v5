import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { ArrowLeft, CheckCircle2, AlertCircle, FileText } from 'lucide-react';
import { formatDate } from '@/lib/utils';

interface GrnItem {
  id: number;
  quantity_received: number;
  quantity_rejected: number;
  rejection_reason?: string;
  condition: string;
  batch_number?: string;
  expiry_date?: string;
  notes?: string;
  purchase_order_item?: {
    id: number;
    quantity_ordered: number;
    unit_price: number;
    product?: { id: number; sku: string; name: string };
  };
}

interface Grn {
  id: number;
  grn_number: string;
  status: string;
  received_at: string;
  notes?: string;
  discrepancy_notes?: string;
  confirmed_at?: string;
  warehouse?: { id: number; name: string };
  location?: { id: number; code: string; name?: string };
  receiver?: { id: number; name: string };
  purchase_order?: { id: number; po_number: string; supplier?: { id: number; name: string } };
  items: GrnItem[];
}

interface Props { grn: Grn }

const COND_COLOR: Record<string, string> = {
  GOOD: 'bg-green-100 text-green-700',
  DAMAGED: 'bg-orange-100 text-orange-700',
  EXPIRED: 'bg-red-100 text-red-700',
};

export default function ReceivingShow({ grn }: Props) {
  function confirm() {
    if (!window.confirm('Confirm GRN? This will post stock-in movements and update the PO. This cannot be undone.')) return;
    router.post(`/procurement/receiving/${grn.id}/confirm`);
  }

  return (
    <AppLayout>
      <Head title={grn.grn_number} />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link href="/procurement/receiving"><Button variant="ghost" size="sm"><ArrowLeft className="mr-1 h-4 w-4" />Back</Button></Link>
            <div>
              <h1 className="font-mono text-2xl font-bold">{grn.grn_number}</h1>
              <div className="flex items-center gap-2">
                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${grn.status === 'CONFIRMED' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'}`}>{grn.status}</span>
                {grn.purchase_order && (
                  <Link href={`/procurement/orders/${grn.purchase_order.id}`} className="text-xs text-blue-600 hover:underline">
                    Against PO {grn.purchase_order.po_number}
                  </Link>
                )}
              </div>
            </div>
          </div>
          {grn.status === 'DRAFT' && (
            <Button onClick={confirm}><CheckCircle2 className="mr-2 h-4 w-4" />Confirm GRN & Post Stock</Button>
          )}
        </div>

        {grn.status === 'DRAFT' && (
          <div className="flex items-center gap-3 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3">
            <AlertCircle className="h-5 w-5 text-yellow-600" />
            <p className="text-sm text-yellow-900">
              This GRN is in <span className="font-medium">DRAFT</span>. Stock has NOT been posted yet. Confirm to add items to inventory.
            </p>
          </div>
        )}

        <div className="grid gap-4 lg:grid-cols-3">
          <Card className="lg:col-span-2">
            <CardHeader><CardTitle className="text-base">Received Lines</CardTitle></CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Item</TableHead>
                    <TableHead className="text-right">Ordered</TableHead>
                    <TableHead className="text-right">Received</TableHead>
                    <TableHead className="text-right">Rejected</TableHead>
                    <TableHead>Condition</TableHead>
                    <TableHead>Batch / Expiry</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {grn.items.map(it => (
                    <TableRow key={it.id}>
                      <TableCell className="text-sm">
                        {it.purchase_order_item?.product
                          ? <><span className="font-mono">{it.purchase_order_item.product.sku}</span> — {it.purchase_order_item.product.name}</>
                          : '—'}
                        {it.notes && <p className="mt-0.5 text-xs text-muted-foreground">{it.notes}</p>}
                      </TableCell>
                      <TableCell className="text-right text-sm">{it.purchase_order_item?.quantity_ordered ?? '—'}</TableCell>
                      <TableCell className="text-right font-medium text-green-700">{it.quantity_received}</TableCell>
                      <TableCell className="text-right text-sm">
                        {it.quantity_rejected > 0
                          ? <span className="font-medium text-red-700">{it.quantity_rejected}</span>
                          : <span className="text-muted-foreground">0</span>}
                        {it.rejection_reason && <p className="text-xs text-muted-foreground">{it.rejection_reason}</p>}
                      </TableCell>
                      <TableCell><span className={`rounded-full px-2 py-0.5 text-xs ${COND_COLOR[it.condition] ?? 'bg-gray-100 text-gray-700'}`}>{it.condition}</span></TableCell>
                      <TableCell className="text-xs">
                        {it.batch_number && <div className="font-mono">{it.batch_number}</div>}
                        {it.expiry_date && <div className="text-muted-foreground">exp {formatDate(it.expiry_date)}</div>}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle className="text-base">Details</CardTitle></CardHeader>
            <CardContent className="space-y-2 text-sm">
              <DetailRow label="Supplier"     value={grn.purchase_order?.supplier?.name ?? '—'} />
              <DetailRow label="Warehouse"    value={grn.warehouse?.name ?? '—'} />
              <DetailRow label="Location"     value={grn.location ? `${grn.location.code}${grn.location.name ? ' — ' + grn.location.name : ''}` : '(default)'} />
              <DetailRow label="Received At"  value={formatDate(grn.received_at)} />
              <DetailRow label="Received By"  value={grn.receiver?.name ?? '—'} />
              {grn.confirmed_at && <DetailRow label="Confirmed" value={formatDate(grn.confirmed_at)} />}
              {grn.notes && <div><p className="text-xs font-medium uppercase tracking-wide text-muted-foreground"><FileText className="mr-1 inline h-3 w-3" />Notes</p><p className="mt-1 whitespace-pre-wrap">{grn.notes}</p></div>}
              {grn.discrepancy_notes && <div><p className="text-xs font-medium uppercase tracking-wide text-orange-700">Discrepancy</p><p className="mt-1 whitespace-pre-wrap text-orange-800">{grn.discrepancy_notes}</p></div>}
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}

function DetailRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-start justify-between gap-2 border-b pb-2 last:border-0">
      <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</span>
      <span className="text-right text-sm">{value}</span>
    </div>
  );
}
