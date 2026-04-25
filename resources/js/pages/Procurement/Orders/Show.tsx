import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { ArrowLeft, Send, XCircle, PackagePlus, FileText } from 'lucide-react';
import { formatDate } from '@/lib/utils';

interface PoItem {
  id: number;
  product?: { id: number; sku: string; name: string };
  quantity_ordered: number;
  quantity_received: number;
  unit_price: number;
  tax_rate: number;
  line_total: number;
  notes?: string;
}

interface Grn {
  id: number;
  grn_number: string;
  status: string;
  received_at: string;
  receiver?: { id: number; name: string };
  items: { id: number; quantity_received: number; quantity_rejected: number; condition: string }[];
}

interface Po {
  id: number;
  po_number: string;
  status: string;
  payment_terms?: string;
  expected_delivery_date?: string;
  currency_code: string;
  exchange_rate: number;
  subtotal: number;
  tax_amount: number;
  total_amount: number;
  notes?: string;
  approved_at?: string;
  sent_at?: string;
  created_at: string;
  supplier?: { id: number; name: string; code: string; contact_person?: string; email?: string; phone?: string; payment_terms?: string };
  warehouse?: { id: number; name: string };
  creator?: { id: number; name: string };
  approver?: { id: number; name: string };
  items: PoItem[];
  receiving_reports: Grn[];
  purchase_request?: { id: number; pr_number: string };
}

interface Props { po: Po }

const STATUS_COLOR: Record<string, string> = {
  DRAFT:              'bg-gray-100 text-gray-700',
  SENT:               'bg-blue-100 text-blue-700',
  PARTIALLY_RECEIVED: 'bg-yellow-100 text-yellow-800',
  RECEIVED:           'bg-green-100 text-green-700',
  CANCELLED:          'bg-red-100 text-red-700',
};

export default function PoShow({ po }: Props) {
  const [cancelOpen, setCancelOpen] = useState(false);
  const [reason, setReason] = useState('');

  const fmt = (n: number) =>
    (po.currency_code === 'PHP' ? '₱' : po.currency_code + ' ') +
    Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });

  function send()   { router.post(`/procurement/orders/${po.id}/send`); }
  function cancel() {
    router.post(`/procurement/orders/${po.id}/cancel`, { reason }, {
      onSuccess: () => { setCancelOpen(false); setReason(''); },
    });
  }

  const canReceive = po.status === 'SENT' || po.status === 'PARTIALLY_RECEIVED';

  return (
    <AppLayout>
      <Head title={po.po_number} />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link href="/procurement/orders"><Button variant="ghost" size="sm"><ArrowLeft className="mr-1 h-4 w-4" />Back</Button></Link>
            <div>
              <h1 className="font-mono text-2xl font-bold">{po.po_number}</h1>
              <div className="flex items-center gap-2">
                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLOR[po.status]}`}>{po.status.replace('_', ' ')}</span>
                {po.purchase_request && (
                  <Link href={`/procurement/requests/${po.purchase_request.id}`} className="text-xs text-blue-600 hover:underline">
                    From PR {po.purchase_request.pr_number}
                  </Link>
                )}
              </div>
            </div>
          </div>
          <div className="flex gap-2">
            {po.status === 'DRAFT' && (
              <>
                <Button variant="outline" onClick={() => setCancelOpen(true)}><XCircle className="mr-2 h-4 w-4" />Cancel</Button>
                <Button onClick={send}><Send className="mr-2 h-4 w-4" />Send to Supplier</Button>
              </>
            )}
            {canReceive && (
              <Link href={`/procurement/receiving/create?po_id=${po.id}`}>
                <Button><PackagePlus className="mr-2 h-4 w-4" />Receive Items (GRN)</Button>
              </Link>
            )}
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-3">
          <Card className="lg:col-span-2">
            <CardHeader><CardTitle className="text-base">Items</CardTitle></CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Item</TableHead>
                    <TableHead className="text-right">Ordered</TableHead>
                    <TableHead className="text-right">Received</TableHead>
                    <TableHead className="text-right">Outstanding</TableHead>
                    <TableHead className="text-right">Unit Price</TableHead>
                    <TableHead className="text-right">Line Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {po.items.map(it => {
                    const outstanding = Math.max(0, it.quantity_ordered - it.quantity_received);
                    return (
                      <TableRow key={it.id}>
                        <TableCell className="text-sm">
                          {it.product ? <><span className="font-mono">{it.product.sku}</span> — {it.product.name}</> : '—'}
                          {it.notes && <p className="mt-0.5 text-xs text-muted-foreground">{it.notes}</p>}
                        </TableCell>
                        <TableCell className="text-right text-sm">{it.quantity_ordered}</TableCell>
                        <TableCell className="text-right text-sm font-medium text-green-700">{it.quantity_received}</TableCell>
                        <TableCell className="text-right text-sm">
                          <span className={outstanding === 0 ? 'text-muted-foreground' : 'font-medium text-orange-700'}>
                            {outstanding}
                          </span>
                        </TableCell>
                        <TableCell className="text-right text-sm">{fmt(it.unit_price)}</TableCell>
                        <TableCell className="text-right font-medium">{fmt(it.line_total)}</TableCell>
                      </TableRow>
                    );
                  })}
                  <TableRow><TableCell colSpan={5} className="text-right font-medium">Subtotal</TableCell><TableCell className="text-right">{fmt(po.subtotal)}</TableCell></TableRow>
                  <TableRow><TableCell colSpan={5} className="text-right font-medium">Tax / Other</TableCell><TableCell className="text-right">{fmt(po.tax_amount)}</TableCell></TableRow>
                  <TableRow className="bg-muted/50"><TableCell colSpan={5} className="text-right font-bold">Total</TableCell><TableCell className="text-right text-lg font-bold">{fmt(po.total_amount)}</TableCell></TableRow>
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <div className="space-y-4">
            <Card>
              <CardHeader><CardTitle className="text-base">Supplier</CardTitle></CardHeader>
              <CardContent className="space-y-2 text-sm">
                <p className="font-medium">{po.supplier?.name}</p>
                <p className="font-mono text-xs text-muted-foreground">{po.supplier?.code}</p>
                {po.supplier?.contact_person && <p>{po.supplier.contact_person}</p>}
                {po.supplier?.phone && <p className="text-xs">{po.supplier.phone}</p>}
                {po.supplier?.email && <p className="text-xs text-muted-foreground">{po.supplier.email}</p>}
              </CardContent>
            </Card>

            <Card>
              <CardHeader><CardTitle className="text-base">Details</CardTitle></CardHeader>
              <CardContent className="space-y-2 text-sm">
                <DetailRow label="Warehouse"     value={po.warehouse?.name ?? '—'} />
                <DetailRow label="Payment Terms" value={po.payment_terms ?? '—'} />
                <DetailRow label="Expected"      value={po.expected_delivery_date ? formatDate(po.expected_delivery_date) : '—'} />
                <DetailRow label="Currency"      value={`${po.currency_code} (${po.exchange_rate} → PHP)`} />
                <DetailRow label="Created"       value={`${formatDate(po.created_at)} by ${po.creator?.name ?? '—'}`} />
                {po.sent_at && <DetailRow label="Sent" value={formatDate(po.sent_at)} />}
                {po.notes && <div className="pt-2"><p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Notes</p><p className="mt-1 whitespace-pre-wrap">{po.notes}</p></div>}
              </CardContent>
            </Card>
          </div>
        </div>

        {/* GRN history */}
        {po.receiving_reports.length > 0 && (
          <Card>
            <CardHeader><CardTitle className="text-base">Receiving History (GRNs)</CardTitle></CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>GRN #</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Received At</TableHead>
                    <TableHead>Received By</TableHead>
                    <TableHead className="text-right">Items</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {po.receiving_reports.map(g => (
                    <TableRow key={g.id}>
                      <TableCell><Link href={`/procurement/receiving/${g.id}`} className="font-mono text-sm font-medium text-primary hover:underline">{g.grn_number}</Link></TableCell>
                      <TableCell><span className={`rounded-full px-2 py-0.5 text-xs ${g.status === 'CONFIRMED' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'}`}>{g.status}</span></TableCell>
                      <TableCell className="text-sm">{formatDate(g.received_at)}</TableCell>
                      <TableCell className="text-sm">{g.receiver?.name ?? '—'}</TableCell>
                      <TableCell className="text-right text-sm">{g.items.length}</TableCell>
                      <TableCell><Link href={`/procurement/receiving/${g.id}`}><FileText className="h-4 w-4 text-muted-foreground" /></Link></TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}
      </div>

      <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Cancel PO {po.po_number}</DialogTitle></DialogHeader>
          <div className="space-y-3">
            <Textarea rows={4} value={reason} onChange={e => setReason(e.target.value)} placeholder="Reason for cancellation..." />
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setCancelOpen(false)}>Back</Button>
              <Button variant="destructive" onClick={cancel} disabled={!reason.trim()}>Cancel PO</Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
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
