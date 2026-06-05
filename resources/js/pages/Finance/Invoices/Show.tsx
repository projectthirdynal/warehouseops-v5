import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  ArrowLeft, Pencil, Send, CheckCircle, XCircle, FileText,
  Plus, Trash2, CreditCard, AlertTriangle,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import {
  Tabs, TabsContent, TabsList, TabsTrigger,
} from '@/components/ui/tabs';

interface ThirdParty {
  id: number;
  name: string;
}

interface Line {
  id: number;
  description: string;
  qty: string;
  unit_price: string;
  discount_amount: string;
  tax_amount: string;
  total_ttc: string;
}

interface Payment {
  id: number;
  amount: string;
  payment_date: string;
  payment_method: string;
  reference_number: string | null;
  recorded_by: { id: number; name: string };
}

interface Invoice {
  id: number;
  ref: string;
  type: string;
  status: string;
  client_name: string;
  client_email: string | null;
  client_phone: string | null;
  client_address: string | null;
  date_invoice: string;
  date_due: string | null;
  date_sent: string | null;
  payment_terms: string | null;
  subtotal: string;
  discount_amount: string;
  tax_amount: string;
  shipping_amount: string;
  total_amount: string;
  amount_paid: string;
  amount_due: string;
  notes: string | null;
  cancel_reason: string | null;
  lines: Line[];
  payments: Payment[];
  created_by: { id: number; name: string };
}

interface Props {
  invoice: Invoice;
  thirdParties: ThirdParty[];
}

const statusBadge: Record<string, string> = {
  DRAFT:     'bg-gray-100 text-gray-700',
  VALIDATED: 'bg-blue-100 text-blue-700',
  SENT:      'bg-purple-100 text-purple-700',
  PARTIAL:   'bg-yellow-100 text-yellow-700',
  PAID:      'bg-green-100 text-green-700',
  OVERDUE:   'bg-red-100 text-red-700',
  CANCELLED: 'bg-gray-200 text-gray-500',
};

export default function InvoiceShow({ invoice }: Props) {
  const [showCancel, setShowCancel] = useState(false);
  const [showPayment, setShowPayment] = useState(false);
  const [cancelReason, setCancelReason] = useState('');

  const { data: payData, setData: setPayData, post: postPayment, processing: payProcessing } = useForm({
    amount: '',
    payment_date: new Date().toISOString().split('T')[0],
    payment_method: 'bank_transfer',
    reference_number: '',
    notes: '',
  });

  const balance = parseFloat(invoice.amount_due);
  const canEdit = ['DRAFT', 'VALIDATED', 'SENT', 'PARTIAL', 'OVERDUE'].includes(invoice.status);
  const canPay = balance > 0 && invoice.status !== 'CANCELLED';

  return (
    <AppLayout>
      <Head title={`Invoice ${invoice.ref}`} />
      <div className="max-w-5xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-3">
            <Button variant="ghost" size="sm" asChild>
              <Link href="/finance/invoices">
                <ArrowLeft className="h-4 w-4 mr-1" /> Back
              </Link>
            </Button>
            <div>
              <h1 className="text-2xl font-bold">{invoice.ref}</h1>
              <p className="text-muted-foreground text-sm">
                {invoice.type.replace('_', ' ')} — {invoice.client_name}
              </p>
            </div>
          </div>
          <div className="flex gap-2">
            {invoice.status === 'DRAFT' && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => router.post(`/finance/invoices/${invoice.id}/validate`)}
              >
                <CheckCircle className="h-4 w-4 mr-1" /> Validate
              </Button>
            )}
            {invoice.status === 'VALIDATED' && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => router.post(`/finance/invoices/${invoice.id}/send`)}
              >
                <Send className="h-4 w-4 mr-1" /> Mark Sent
              </Button>
            )}
            {canPay && (
              <Button size="sm" onClick={() => setShowPayment(true)}>
                <CreditCard className="h-4 w-4 mr-1" /> Record Payment
              </Button>
            )}
            {invoice.status !== 'CANCELLED' && invoice.status !== 'PAID' && (
              <Button
                variant="destructive"
                size="sm"
                onClick={() => setShowCancel(true)}
              >
                <XCircle className="h-4 w-4 mr-1" /> Cancel
              </Button>
            )}
          </div>
        </div>

        <Badge className={statusBadge[invoice.status] ?? 'bg-gray-100'}>
          {invoice.status}
        </Badge>

        {/* Summary */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium text-muted-foreground">Subtotal</CardTitle></CardHeader>
            <CardContent><p className="text-xl font-bold">₱{parseFloat(invoice.subtotal).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</p></CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium text-muted-foreground">Tax</CardTitle></CardHeader>
            <CardContent><p className="text-xl font-bold">₱{parseFloat(invoice.tax_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</p></CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium text-muted-foreground">Total</CardTitle></CardHeader>
            <CardContent><p className="text-xl font-bold">₱{parseFloat(invoice.total_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</p></CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium text-muted-foreground">Balance Due</CardTitle></CardHeader>
            <CardContent><p className={`text-xl font-bold ${balance > 0 ? 'text-red-600' : 'text-green-600'}`}>₱{balance.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</p></CardContent>
          </Card>
        </div>

        <Tabs defaultValue="lines">
          <TabsList>
            <TabsTrigger value="lines">Lines</TabsTrigger>
            <TabsTrigger value="payments">Payments ({invoice.payments.length})</TabsTrigger>
            <TabsTrigger value="details">Details</TabsTrigger>
          </TabsList>

          <TabsContent value="lines" className="space-y-4">
            <div className="rounded-md border bg-white">
              <table className="w-full text-sm">
                <thead className="bg-muted/50">
                  <tr>
                    <th className="px-4 py-3 text-left">Description</th>
                    <th className="px-4 py-3 text-right">Qty</th>
                    <th className="px-4 py-3 text-right">Unit Price</th>
                    <th className="px-4 py-3 text-right">Discount</th>
                    <th className="px-4 py-3 text-right">Tax</th>
                    <th className="px-4 py-3 text-right">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {invoice.lines.map((line) => (
                    <tr key={line.id} className="border-t">
                      <td className="px-4 py-3">{line.description}</td>
                      <td className="px-4 py-3 text-right">{line.qty}</td>
                      <td className="px-4 py-3 text-right">₱{parseFloat(line.unit_price).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                      <td className="px-4 py-3 text-right">₱{parseFloat(line.discount_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                      <td className="px-4 py-3 text-right">₱{parseFloat(line.tax_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                      <td className="px-4 py-3 text-right font-medium">₱{parseFloat(line.total_ttc).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                    </tr>
                  ))}
                  {invoice.lines.length === 0 && (
                    <tr><td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">No lines</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </TabsContent>

          <TabsContent value="payments" className="space-y-4">
            {invoice.payments.length === 0 ? (
              <p className="text-muted-foreground text-center py-8">No payments recorded.</p>
            ) : (
              <div className="rounded-md border bg-white">
                <table className="w-full text-sm">
                  <thead className="bg-muted/50">
                    <tr>
                      <th className="px-4 py-3 text-left">Date</th>
                      <th className="px-4 py-3 text-left">Method</th>
                      <th className="px-4 py-3 text-right">Amount</th>
                      <th className="px-4 py-3 text-left">Reference</th>
                      <th className="px-4 py-3 text-left">Recorded By</th>
                    </tr>
                  </thead>
                  <tbody>
                    {invoice.payments.map((p) => (
                      <tr key={p.id} className="border-t">
                        <td className="px-4 py-3">{p.payment_date}</td>
                        <td className="px-4 py-3 capitalize">{p.payment_method}</td>
                        <td className="px-4 py-3 text-right font-medium">₱{parseFloat(p.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                        <td className="px-4 py-3">{p.reference_number ?? '—'}</td>
                        <td className="px-4 py-3">{p.recorded_by.name}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </TabsContent>

          <TabsContent value="details" className="space-y-4">
            <Card>
              <CardContent className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-6">
                <div><Label className="text-xs text-muted-foreground">Client Name</Label><p className="font-medium">{invoice.client_name}</p></div>
                <div><Label className="text-xs text-muted-foreground">Email</Label><p>{invoice.client_email ?? '—'}</p></div>
                <div><Label className="text-xs text-muted-foreground">Phone</Label><p>{invoice.client_phone ?? '—'}</p></div>
                <div><Label className="text-xs text-muted-foreground">Address</Label><p>{invoice.client_address ?? '—'}</p></div>
                <div><Label className="text-xs text-muted-foreground">Invoice Date</Label><p>{invoice.date_invoice}</p></div>
                <div><Label className="text-xs text-muted-foreground">Due Date</Label><p>{invoice.date_due ?? '—'}</p></div>
                <div><Label className="text-xs text-muted-foreground">Date Sent</Label><p>{invoice.date_sent ?? '—'}</p></div>
                <div><Label className="text-xs text-muted-foreground">Payment Terms</Label><p>{invoice.payment_terms ?? '—'}</p></div>
                <div className="md:col-span-2"><Label className="text-xs text-muted-foreground">Notes</Label><p>{invoice.notes ?? '—'}</p></div>
                {invoice.cancel_reason && (
                  <div className="md:col-span-2">
                    <Label className="text-xs text-muted-foreground">Cancel Reason</Label>
                    <p className="text-red-600">{invoice.cancel_reason}</p>
                  </div>
                )}
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>

        {/* Cancel Dialog */}
        <Dialog open={showCancel} onOpenChange={setShowCancel}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Cancel Invoice</DialogTitle>
            </DialogHeader>
            <p className="text-sm text-muted-foreground">
              Are you sure? This action cannot be undone.
            </p>
            <div className="space-y-2">
              <Label>Reason (optional)</Label>
              <Input value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} placeholder="e.g. Duplicate invoice" />
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setShowCancel(false)}>Close</Button>
              <Button
                variant="destructive"
                onClick={() => {
                  router.post(`/finance/invoices/${invoice.id}/cancel`, { reason: cancelReason });
                  setShowCancel(false);
                }}
              >
                Cancel Invoice
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Payment Dialog */}
        <Dialog open={showPayment} onOpenChange={setShowPayment}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Record Payment</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                postPayment(`/finance/invoices/${invoice.id}/payments`);
                setShowPayment(false);
              }}
              className="space-y-4"
            >
              <div className="space-y-2">
                <Label>Amount (max ₱{balance.toFixed(2)})</Label>
                <Input
                  type="number"
                  step="0.01"
                  max={balance}
                  value={payData.amount}
                  onChange={(e) => setPayData('amount', e.target.value)}
                  required
                />
              </div>
              <div className="space-y-2">
                <Label>Payment Date</Label>
                <Input
                  type="date"
                  value={payData.payment_date}
                  onChange={(e) => setPayData('payment_date', e.target.value)}
                  required
                />
              </div>
              <div className="space-y-2">
                <Label>Method</Label>
                <Select
                  value={payData.payment_method}
                  onValueChange={(v) => setPayData('payment_method', v)}
                >
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="cash">Cash</SelectItem>
                    <SelectItem value="bank_transfer">Bank Transfer</SelectItem>
                    <SelectItem value="gcash">GCash</SelectItem>
                    <SelectItem value="check">Check</SelectItem>
                    <SelectItem value="cod">COD</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Reference Number</Label>
                <Input value={payData.reference_number} onChange={(e) => setPayData('reference_number', e.target.value)} />
              </div>
              <DialogFooter>
                <Button variant="outline" type="button" onClick={() => setShowPayment(false)}>Close</Button>
                <Button type="submit" disabled={payProcessing}>Record Payment</Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </AppLayout>
  );
}
