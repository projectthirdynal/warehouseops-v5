import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, FileText } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface ThirdParty {
  id: number;
  name: string;
  type: string;
}

interface Props {
  thirdParties: ThirdParty[];
}

export default function SupplierInvoiceCreate({ thirdParties }: Props) {
  const { data, setData, post, processing, errors } = useForm({
    third_party_id: '',
    date_invoice: new Date().toISOString().split('T')[0],
    date_due: '',
    total_amount: '',
    tax_rate: '0',
    notes: '',
  });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    post('/finance/supplier-invoices');
  }

  const total = parseFloat(data.total_amount) || 0;
  const taxRate = parseFloat(data.tax_rate) || 0;
  const subtotal = taxRate > 0 ? total / (1 + taxRate / 100) : total;
  const taxAmount = total - subtotal;

  return (
    <AppLayout>
      <Head title="New Supplier Invoice" />
      <div className="max-w-2xl mx-auto space-y-4">
        <div className="flex items-center gap-3">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/finance/supplier-invoices">
              <ArrowLeft className="h-4 w-4 mr-1" /> Back
            </Link>
          </Button>
          <h1 className="text-xl font-bold font-display">New Supplier Invoice</h1>
        </div>

        <form onSubmit={submit} className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Invoice Details</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2 md:col-span-2">
                <Label>Supplier</Label>
                <Select
                  value={data.third_party_id}
                  onValueChange={(v) => setData('third_party_id', v)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select supplier..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="">Unknown Supplier</SelectItem>
                    {thirdParties.map((tp) => (
                      <SelectItem key={tp.id} value={tp.id.toString()}>
                        {tp.name} ({tp.type})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {errors.third_party_id && (
                  <p className="text-destructive text-sm">{errors.third_party_id}</p>
                )}
              </div>

              <div className="space-y-2">
                <Label>Invoice Date</Label>
                <Input
                  type="date"
                  value={data.date_invoice}
                  onChange={(e) => setData('date_invoice', e.target.value)}
                />
                {errors.date_invoice && (
                  <p className="text-destructive text-sm">{errors.date_invoice}</p>
                )}
              </div>

              <div className="space-y-2">
                <Label>Due Date</Label>
                <Input
                  type="date"
                  value={data.date_due}
                  onChange={(e) => setData('date_due', e.target.value)}
                />
              </div>

              <div className="space-y-2">
                <Label>Total Amount (TTC)</Label>
                <Input
                  type="number"
                  step="0.01"
                  min="0"
                  value={data.total_amount}
                  onChange={(e) => setData('total_amount', e.target.value)}
                  placeholder="0.00"
                />
                {errors.total_amount && (
                  <p className="text-destructive text-sm">{errors.total_amount}</p>
                )}
              </div>

              <div className="space-y-2">
                <Label>Tax Rate (%)</Label>
                <Input
                  type="number"
                  step="0.01"
                  min="0"
                  max="100"
                  value={data.tax_rate}
                  onChange={(e) => setData('tax_rate', e.target.value)}
                />
                {errors.tax_rate && <p className="text-destructive text-sm">{errors.tax_rate}</p>}
              </div>

              <div className="space-y-2 md:col-span-2">
                <Label>Notes</Label>
                <Textarea
                  value={data.notes}
                  onChange={(e) => setData('notes', e.target.value)}
                  placeholder="Internal notes..."
                />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Summary</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Subtotal (excl. tax)</span>
                <span>
                  ₱
                  {subtotal.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                  })}
                </span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Tax Amount</span>
                <span>
                  ₱
                  {taxAmount.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                  })}
                </span>
              </div>
              <div className="flex justify-between text-lg font-bold pt-2 border-t">
                <span>Total</span>
                <span>
                  ₱
                  {total.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                  })}
                </span>
              </div>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-3">
            <Button variant="outline" asChild>
              <Link href="/finance/supplier-invoices">Cancel</Link>
            </Button>
            <Button type="submit" disabled={processing}>
              <FileText className="mr-1.5 h-4 w-4" />
              {processing ? 'Creating...' : 'Create Supplier Invoice'}
            </Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
