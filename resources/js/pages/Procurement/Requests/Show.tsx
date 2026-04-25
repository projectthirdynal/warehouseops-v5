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
import { ArrowLeft, Send, CheckCircle2, XCircle, ShoppingCart } from 'lucide-react';
import { formatDate } from '@/lib/utils';

interface Item {
  id: number;
  product?: { id: number; sku: string; name: string };
  quantity_requested: number;
  unit_price_estimate: number;
  notes?: string;
}

interface Pr {
  id: number;
  pr_number: string;
  status: string;
  priority: string;
  department?: string;
  reason?: string;
  needed_by_date?: string;
  estimated_total: number;
  rejected_reason?: string;
  approved_at?: string;
  created_at: string;
  requester?: { id: number; name: string };
  approver?: { id: number; name: string };
  items: Item[];
}

interface Props { pr: Pr }

const STATUS_COLOR: Record<string, string> = {
  DRAFT:     'bg-gray-100 text-gray-700',
  SUBMITTED: 'bg-blue-100 text-blue-700',
  APPROVED:  'bg-green-100 text-green-700',
  CONVERTED: 'bg-emerald-100 text-emerald-700',
  REJECTED:  'bg-red-100 text-red-700',
  CANCELLED: 'bg-orange-100 text-orange-700',
};

export default function PrShow({ pr }: Props) {
  const [rejectOpen, setRejectOpen] = useState(false);
  const [reason, setReason] = useState('');

  function submit() {
    router.post(`/procurement/requests/${pr.id}/submit`);
  }
  function approve() {
    router.post(`/procurement/requests/${pr.id}/approve`);
  }
  function reject() {
    router.post(`/procurement/requests/${pr.id}/reject`, { reason }, {
      onSuccess: () => { setRejectOpen(false); setReason(''); },
    });
  }

  return (
    <AppLayout>
      <Head title={pr.pr_number} />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link href="/procurement/requests"><Button variant="ghost" size="sm"><ArrowLeft className="mr-1 h-4 w-4" />Back</Button></Link>
            <div>
              <h1 className="font-mono text-2xl font-bold">{pr.pr_number}</h1>
              <div className="flex items-center gap-2">
                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLOR[pr.status]}`}>{pr.status}</span>
                <span className="text-xs text-muted-foreground">Priority: {pr.priority}</span>
              </div>
            </div>
          </div>
          <div className="flex gap-2">
            {pr.status === 'DRAFT' && (
              <Button onClick={submit}><Send className="mr-2 h-4 w-4" />Submit for Approval</Button>
            )}
            {pr.status === 'SUBMITTED' && (
              <>
                <Button variant="outline" onClick={() => setRejectOpen(true)}><XCircle className="mr-2 h-4 w-4" />Reject</Button>
                <Button onClick={approve}><CheckCircle2 className="mr-2 h-4 w-4" />Approve</Button>
              </>
            )}
            {pr.status === 'APPROVED' && (
              <Link href={`/procurement/orders/create?pr_id=${pr.id}`}>
                <Button><ShoppingCart className="mr-2 h-4 w-4" />Convert to PO</Button>
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
                    <TableHead className="text-right">Quantity</TableHead>
                    <TableHead className="text-right">Est. Unit ₱</TableHead>
                    <TableHead className="text-right">Line Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {pr.items.map(it => (
                    <TableRow key={it.id}>
                      <TableCell className="text-sm">
                        {it.product ? <><span className="font-mono">{it.product.sku}</span> — {it.product.name}</> : '—'}
                        {it.notes && <p className="mt-0.5 text-xs text-muted-foreground">{it.notes}</p>}
                      </TableCell>
                      <TableCell className="text-right text-sm">{it.quantity_requested}</TableCell>
                      <TableCell className="text-right text-sm">₱{Number(it.unit_price_estimate).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</TableCell>
                      <TableCell className="text-right font-medium">₱{(it.quantity_requested * it.unit_price_estimate).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</TableCell>
                    </TableRow>
                  ))}
                  <TableRow className="bg-muted/50">
                    <TableCell colSpan={3} className="text-right font-medium">Estimated Total</TableCell>
                    <TableCell className="text-right text-lg font-bold">₱{Number(pr.estimated_total).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle className="text-base">Details</CardTitle></CardHeader>
            <CardContent className="space-y-3 text-sm">
              <DetailRow label="Department"   value={pr.department ?? '—'} />
              <DetailRow label="Needed By"    value={pr.needed_by_date ? formatDate(pr.needed_by_date) : '—'} />
              <DetailRow label="Requester"    value={pr.requester?.name ?? '—'} />
              <DetailRow label="Created"      value={formatDate(pr.created_at)} />
              {pr.approved_at && <DetailRow label="Approved"     value={`${formatDate(pr.approved_at)} by ${pr.approver?.name ?? '—'}`} />}
              {pr.reason && <div><p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Reason</p><p className="mt-1 whitespace-pre-wrap">{pr.reason}</p></div>}
              {pr.rejected_reason && <div><p className="text-xs font-medium uppercase tracking-wide text-red-600">Rejection</p><p className="mt-1 whitespace-pre-wrap text-red-700">{pr.rejected_reason}</p></div>}
            </CardContent>
          </Card>
        </div>
      </div>

      <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Reject Purchase Request</DialogTitle></DialogHeader>
          <div className="space-y-3">
            <Textarea rows={4} value={reason} onChange={e => setReason(e.target.value)} placeholder="Explain why this is being rejected..." />
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setRejectOpen(false)}>Cancel</Button>
              <Button variant="destructive" onClick={reject} disabled={!reason.trim()}>Reject PR</Button>
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
