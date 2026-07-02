import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, CheckCircle, XCircle } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';

interface ThirdParty {
  id: number;
  name: string;
}

interface Invoice {
  id: number;
  ref: string;
  status: string;
  supplier_name: string;
  supplier_email: string | null;
  supplier_phone: string | null;
  supplier_address: string | null;
  date_invoice: string;
  date_due: string | null;
  date_receipt: string | null;
  payment_terms: string | null;
  subtotal: string;
  tax_amount: string;
  total_amount: string;
  amount_paid: string;
  amount_due: string;
  notes: string | null;
  cancel_reason: string | null;
  third_party?: ThirdParty | null;
  created_by: { id: number; name: string };
}

interface Props {
  invoice: Invoice;
}

const statusBadge: Record<string, string> = {
  DRAFT: 'bg-muted text-muted-foreground',
  VALIDATED: 'bg-info/10 text-info',
  PARTIAL: 'bg-warning/10 text-warning',
  PAID: 'bg-success/10 text-success',
  OVERDUE: 'bg-destructive/10 text-destructive',
  CANCELLED: 'bg-muted/80 text-muted-foreground',
};

export default function SupplierInvoiceShow({ invoice }: Props) {
  const [showCancel, setShowCancel] = useState(false);
  const [cancelReason, setCancelReason] = useState('');

  const balance = parseFloat(invoice.amount_due);

  return (
    <AppLayout>
      <Head title={`Supplier Invoice ${invoice.ref}`} />
      <div className="max-w-4xl mx-auto space-y-4">
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-3">
            <Button variant="ghost" size="sm" asChild>
              <Link href="/finance/supplier-invoices">
                <ArrowLeft className="h-4 w-4 mr-1" /> Back
              </Link>
            </Button>
            <div>
              <h1 className="text-xl font-bold font-display">{invoice.ref}</h1>
              <p className="text-muted-foreground text-sm">{invoice.supplier_name}</p>
            </div>
          </div>
          <div className="flex gap-2">
            {invoice.status === 'DRAFT' && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => router.post(`/finance/supplier-invoices/${invoice.id}/validate`)}
              >
                <CheckCircle className="h-4 w-4 mr-1" /> Validate
              </Button>
            )}
            {invoice.status !== 'CANCELLED' && invoice.status !== 'PAID' && (
              <Button variant="destructive" size="sm" onClick={() => setShowCancel(true)}>
                <XCircle className="h-4 w-4 mr-1" /> Cancel
              </Button>
            )}
          </div>
        </div>

        <Badge className={statusBadge[invoice.status] ?? 'bg-muted'}>{invoice.status}</Badge>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Subtotal</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-xl font-bold">
                ₱
                {parseFloat(invoice.subtotal).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Tax</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-xl font-bold">
                ₱
                {parseFloat(invoice.tax_amount).toLocaleString('en-PH', {
                  minimumFractionDigits: 2,
                })}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Total</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-xl font-bold">
                ₱
                {parseFloat(invoice.total_amount).toLocaleString('en-PH', {
                  minimumFractionDigits: 2,
                })}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Balance Due
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p
                className={`text-xl font-bold ${balance > 0 ? 'text-destructive' : 'text-success'}`}
              >
                ₱{balance.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </p>
            </CardContent>
          </Card>
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Details</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <Label className="text-xs text-muted-foreground">Supplier</Label>
              <p className="font-medium">{invoice.supplier_name}</p>
            </div>
            <div>
              <Label className="text-xs text-muted-foreground">Email</Label>
              <p>{invoice.supplier_email ?? '—'}</p>
            </div>
            <div>
              <Label className="text-xs text-muted-foreground">Phone</Label>
              <p>{invoice.supplier_phone ?? '—'}</p>
            </div>
            <div>
              <Label className="text-xs text-muted-foreground">Address</Label>
              <p>{invoice.supplier_address ?? '—'}</p>
            </div>
            <div>
              <Label className="text-xs text-muted-foreground">Invoice Date</Label>
              <p>{invoice.date_invoice}</p>
            </div>
            <div>
              <Label className="text-xs text-muted-foreground">Due Date</Label>
              <p>{invoice.date_due ?? '—'}</p>
            </div>
            <div>
              <Label className="text-xs text-muted-foreground">Receipt Date</Label>
              <p>{invoice.date_receipt ?? '—'}</p>
            </div>
            <div>
              <Label className="text-xs text-muted-foreground">Payment Terms</Label>
              <p>{invoice.payment_terms ?? '—'}</p>
            </div>
            <div className="md:col-span-2">
              <Label className="text-xs text-muted-foreground">Notes</Label>
              <p>{invoice.notes ?? '—'}</p>
            </div>
            {invoice.cancel_reason && (
              <div className="md:col-span-2">
                <Label className="text-xs text-muted-foreground">Cancel Reason</Label>
                <p className="text-destructive">{invoice.cancel_reason}</p>
              </div>
            )}
          </CardContent>
        </Card>

        <Dialog open={showCancel} onOpenChange={setShowCancel}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Cancel Supplier Invoice</DialogTitle>
            </DialogHeader>
            <p className="text-sm text-muted-foreground">This action cannot be undone.</p>
            <div className="space-y-2">
              <Label>Reason (optional)</Label>
              <Input value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} />
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setShowCancel(false)}>
                Close
              </Button>
              <Button
                variant="destructive"
                onClick={() => {
                  router.post(`/finance/supplier-invoices/${invoice.id}/cancel`, {
                    reason: cancelReason,
                  });
                  setShowCancel(false);
                }}
              >
                Cancel
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </AppLayout>
  );
}
