import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  ArrowLeft, Plus, Trash2, FileText,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';

interface Product {
  id: number;
  name: string;
  sku: string;
  unit_price: string;
}

interface ThirdParty {
  id: number;
  name: string;
  type: string;
}

interface Props {
  thirdParties: ThirdParty[];
  products: Product[];
}

interface Line {
  product_id: string;
  description: string;
  qty: string;
  unit_price: string;
  tax_rate: string;
  discount_pct: string;
}

export default function InvoiceCreate({ thirdParties, products }: Props) {
  const { data, setData, post, processing, errors } = useForm({
    third_party_id: '',
    type: 'standard',
    date_invoice: new Date().toISOString().split('T')[0],
    date_due: '',
    payment_terms: 'NET30',
    notes: '',
    lines: [
      { product_id: '', description: '', qty: '1', unit_price: '', tax_rate: '12', discount_pct: '0' },
    ] as Line[],
  });

  function addLine() {
    setData('lines', [
      ...data.lines,
      { product_id: '', description: '', qty: '1', unit_price: '', tax_rate: '12', discount_pct: '0' },
    ]);
  }

  function removeLine(idx: number) {
    if (data.lines.length <= 1) return;
    const newLines = data.lines.filter((_, i) => i !== idx);
    setData('lines', newLines);
  }

  function updateLine(idx: number, field: keyof Line, value: string) {
    const newLines = [...data.lines];
    newLines[idx] = { ...newLines[idx], [field]: value };
    setData('lines', newLines);
  }

  function handleProductSelect(idx: number, productId: string) {
    const product = products.find((p) => p.id.toString() === productId);
    if (!product) return;
    const newLines = [...data.lines];
    newLines[idx] = {
      ...newLines[idx],
      product_id: productId,
      description: product.name,
      unit_price: product.unit_price,
    };
    setData('lines', newLines);
  }

  function lineTotal(line: Line): number {
    const qty = parseFloat(line.qty) || 0;
    const price = parseFloat(line.unit_price) || 0;
    const discount = parseFloat(line.discount_pct) || 0;
    const tax = parseFloat(line.tax_rate) || 0;
    const subtotal = qty * price;
    const afterDiscount = subtotal * (1 - discount / 100);
    return afterDiscount * (1 + tax / 100);
  }

  const grandTotal = data.lines.reduce((sum, l) => sum + lineTotal(l), 0);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    post('/finance/invoices');
  }

  return (
    <AppLayout>
      <Head title="Create Invoice" />
      <div className="max-w-4xl mx-auto space-y-6">
        <div className="flex items-center gap-3">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/finance/invoices">
              <ArrowLeft className="h-4 w-4 mr-1" /> Back
            </Link>
          </Button>
          <h1 className="text-2xl font-bold">New Invoice</h1>
        </div>

        <form onSubmit={submit} className="space-y-6">
          {/* Client & Header */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Invoice Details</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Client</Label>
                <Select
                  value={data.third_party_id}
                  onValueChange={(v) => setData('third_party_id', v)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select client..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="">Walk-in Customer</SelectItem>
                    {thirdParties.map((tp) => (
                      <SelectItem key={tp.id} value={tp.id.toString()}>
                        {tp.name} ({tp.type})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {errors.third_party_id && <p className="text-red-500 text-sm">{errors.third_party_id}</p>}
              </div>

              <div className="space-y-2">
                <Label>Type</Label>
                <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="standard">Standard</SelectItem>
                    <SelectItem value="credit_note">Credit Note</SelectItem>
                    <SelectItem value="deposit">Deposit</SelectItem>
                    <SelectItem value="proforma">Proforma</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Invoice Date</Label>
                <Input
                  type="date"
                  value={data.date_invoice}
                  onChange={(e) => setData('date_invoice', e.target.value)}
                />
                {errors.date_invoice && <p className="text-red-500 text-sm">{errors.date_invoice}</p>}
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
                <Label>Payment Terms</Label>
                <Select value={data.payment_terms} onValueChange={(v) => setData('payment_terms', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="COD">COD</SelectItem>
                    <SelectItem value="NET30">NET30</SelectItem>
                    <SelectItem value="NET60">NET60</SelectItem>
                    <SelectItem value="IMMEDIATE">Immediate</SelectItem>
                  </SelectContent>
                </Select>
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

          {/* Lines */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="text-base">Invoice Lines</CardTitle>
              <Button type="button" variant="outline" size="sm" onClick={addLine}>
                <Plus className="h-4 w-4 mr-1" /> Add Line
              </Button>
            </CardHeader>
            <CardContent className="space-y-4">
              {data.lines.map((line, idx) => (
                <div key={idx} className="grid grid-cols-12 gap-3 items-end border rounded-lg p-3">
                  <div className="col-span-12 md:col-span-3 space-y-1">
                    <Label className="text-xs">Product</Label>
                    <Select
                      value={line.product_id}
                      onValueChange={(v) => handleProductSelect(idx, v)}
                    >
                      <SelectTrigger className="h-9">
                        <SelectValue placeholder="Select..." />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="">Custom</SelectItem>
                        {products.map((p) => (
                          <SelectItem key={p.id} value={p.id.toString()}>
                            {p.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="col-span-12 md:col-span-3 space-y-1">
                    <Label className="text-xs">Description</Label>
                    <Input
                      value={line.description}
                      onChange={(e) => updateLine(idx, 'description', e.target.value)}
                      className="h-9"
                    />
                  </div>
                  <div className="col-span-4 md:col-span-1 space-y-1">
                    <Label className="text-xs">Qty</Label>
                    <Input
                      type="number"
                      step="0.001"
                      value={line.qty}
                      onChange={(e) => updateLine(idx, 'qty', e.target.value)}
                      className="h-9"
                    />
                  </div>
                  <div className="col-span-4 md:col-span-2 space-y-1">
                    <Label className="text-xs">Unit Price</Label>
                    <Input
                      type="number"
                      step="0.01"
                      value={line.unit_price}
                      onChange={(e) => updateLine(idx, 'unit_price', e.target.value)}
                      className="h-9"
                    />
                  </div>
                  <div className="col-span-2 md:col-span-1 space-y-1">
                    <Label className="text-xs">Tax %</Label>
                    <Input
                      type="number"
                      value={line.tax_rate}
                      onChange={(e) => updateLine(idx, 'tax_rate', e.target.value)}
                      className="h-9"
                    />
                  </div>
                  <div className="col-span-2 md:col-span-1 space-y-1">
                    <Label className="text-xs">Disc %</Label>
                    <Input
                      type="number"
                      value={line.discount_pct}
                      onChange={(e) => updateLine(idx, 'discount_pct', e.target.value)}
                      className="h-9"
                    />
                  </div>
                  <div className="col-span-12 md:col-span-1 flex justify-end">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => removeLine(idx)}
                      disabled={data.lines.length <= 1}
                    >
                      <Trash2 className="h-4 w-4 text-red-500" />
                    </Button>
                  </div>
                </div>
              ))}

              <div className="flex justify-end">
                <div className="text-right">
                  <p className="text-sm text-muted-foreground">Grand Total</p>
                  <p className="text-2xl font-bold">
                    ₱{grandTotal.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>

          {errors.lines && <p className="text-red-500 text-sm">{errors.lines}</p>}

          <div className="flex justify-end gap-3">
            <Button variant="outline" asChild>
              <Link href="/finance/invoices">Cancel</Link>
            </Button>
            <Button type="submit" disabled={processing}>
              <FileText className="mr-2 h-4 w-4" />
              {processing ? 'Creating...' : 'Create Invoice'}
            </Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
