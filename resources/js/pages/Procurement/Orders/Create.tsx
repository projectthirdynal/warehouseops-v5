import { useState, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';

interface Item     { id: number; sku: string; name: string; cost_price: number }
interface Supplier { id: number; name: string; code: string; payment_terms?: string }
interface Warehouse { id: number; name: string; code: string }
interface Uom { id: number; name: string; abbreviation: string }

interface PrItem {
  id: number;
  product_id?: number;
  supply_id?: number;
  quantity_requested: number;
  unit_price_estimate: number;
  product?: { id: number; sku: string; name: string };
}
interface Pr { id: number; pr_number: string; items: PrItem[] }

interface Props {
  suppliers:  Supplier[];
  warehouses: Warehouse[];
  products:   Item[];
  supplies:   Item[];
  uoms:       Uom[];
  pr?: Pr | null;
}

interface Line {
  product_id: number | null;
  supply_id: number | null;
  uom_id: number | null;
  quantity_ordered: number;
  unit_price: number;
  tax_rate: number;
}
const blankLine = (): Line => ({
  product_id: null, supply_id: null, uom_id: null,
  quantity_ordered: 1, unit_price: 0, tax_rate: 0,
});

export default function PoCreate({ suppliers, warehouses, products, supplies, uoms, pr }: Props) {
  const [supplierId, setSupplierId]    = useState<number | ''>('');
  const [warehouseId, setWarehouseId]  = useState<number | ''>(warehouses[0]?.id ?? '');
  const [paymentTerms, setPaymentTerms] = useState('');
  const [expectedDate, setExpectedDate] = useState('');
  const [currencyCode, setCurrencyCode] = useState('PHP');
  const [exchangeRate, setExchangeRate] = useState(1);
  const [taxAmount, setTaxAmount]       = useState(0);
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<Line[]>([blankLine()]);
  const [saving, setSaving] = useState(false);

  // Pre-fill from PR if provided
  useEffect(() => {
    if (pr && pr.items.length) {
      setLines(pr.items.map(it => ({
        product_id: it.product_id ?? null,
        supply_id:  it.supply_id ?? null,
        uom_id:     null,
        quantity_ordered: it.quantity_requested,
        unit_price:       Number(it.unit_price_estimate),
        tax_rate:         0,
      })));
    }
  }, [pr]);

  function updateLine(idx: number, patch: Partial<Line>) {
    setLines(ls => ls.map((l, i) => i === idx ? { ...l, ...patch } : l));
  }
  const addLine    = () => setLines(ls => [...ls, blankLine()]);
  const removeLine = (i: number) => setLines(ls => ls.filter((_, x) => x !== i));

  const subtotal = lines.reduce((s, l) => s + (l.quantity_ordered * l.unit_price), 0);
  const total    = subtotal + Number(taxAmount);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!supplierId || !warehouseId) return;
    setSaving(true);
    const payload = {
      pr_id: pr?.id ?? null,
      supplier_id: supplierId,
      warehouse_id: warehouseId,
      payment_terms: paymentTerms,
      expected_delivery_date: expectedDate || null,
      currency_code: currencyCode,
      exchange_rate: exchangeRate,
      tax_amount: taxAmount,
      notes,
      items: lines.filter(l => l.product_id || l.supply_id),
    };
    router.post('/procurement/orders', payload as never, {
      onFinish: () => setSaving(false),
    });
  }

  return (
    <AppLayout>
      <Head title="New Purchase Order" />
      <div className="space-y-6 p-6">
        <div className="flex items-center gap-3">
          <Link href="/procurement/orders"><Button variant="ghost" size="sm"><ArrowLeft className="mr-1 h-4 w-4" />Back</Button></Link>
          <div>
            <h1 className="text-2xl font-bold">New Purchase Order</h1>
            {pr && <p className="text-sm text-muted-foreground">From PR <span className="font-mono">{pr.pr_number}</span></p>}
          </div>
        </div>

        <form onSubmit={submit} className="space-y-6">
          <Card>
            <CardHeader><CardTitle className="text-base">Header</CardTitle></CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <div className="space-y-1">
                <Label>Supplier *</Label>
                <Select value={supplierId ? String(supplierId) : ''} onValueChange={(v) => {
                  const s = suppliers.find(x => x.id === Number(v));
                  setSupplierId(Number(v));
                  if (s?.payment_terms && !paymentTerms) setPaymentTerms(s.payment_terms);
                }}>
                  <SelectTrigger><SelectValue placeholder="Select supplier..." /></SelectTrigger>
                  <SelectContent>
                    {suppliers.map(s => <SelectItem key={s.id} value={String(s.id)}>{s.name} <span className="text-xs text-muted-foreground">({s.code})</span></SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1">
                <Label>Warehouse *</Label>
                <Select value={warehouseId ? String(warehouseId) : ''} onValueChange={(v) => setWarehouseId(Number(v))}>
                  <SelectTrigger><SelectValue placeholder="Select warehouse..." /></SelectTrigger>
                  <SelectContent>
                    {warehouses.map(w => <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1">
                <Label>Expected Delivery</Label>
                <Input type="date" value={expectedDate} onChange={e => setExpectedDate(e.target.value)} />
              </div>
              <div className="space-y-1">
                <Label>Payment Terms</Label>
                <Input value={paymentTerms} onChange={e => setPaymentTerms(e.target.value)} placeholder="NET_30, COD..." />
              </div>
              <div className="space-y-1">
                <Label>Currency *</Label>
                <Select value={currencyCode} onValueChange={(v) => { setCurrencyCode(v); if (v === 'PHP') setExchangeRate(1); }}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="PHP">PHP — Philippine Peso</SelectItem>
                    <SelectItem value="USD">USD — US Dollar</SelectItem>
                    <SelectItem value="CNY">CNY — Chinese Yuan</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1">
                <Label>Exchange Rate (to PHP)</Label>
                <Input type="number" min={0.000001} step="0.000001" value={exchangeRate} onChange={e => setExchangeRate(Number(e.target.value))} disabled={currencyCode === 'PHP'} />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="text-base">Line Items</CardTitle>
              <Button type="button" size="sm" variant="outline" onClick={addLine}><Plus className="mr-1 h-3.5 w-3.5" />Add line</Button>
            </CardHeader>
            <CardContent className="space-y-3">
              {lines.map((line, idx) => (
                <div key={idx} className="grid grid-cols-12 items-end gap-2 rounded-md border p-3">
                  <div className="col-span-5 space-y-1">
                    <Label className="text-xs">Item *</Label>
                    <Select value={line.product_id ? `p:${line.product_id}` : line.supply_id ? `s:${line.supply_id}` : ''}
                      onValueChange={(v) => {
                        const [type, id] = v.split(':');
                        if (type === 'p') {
                          const p = products.find(x => x.id === Number(id));
                          updateLine(idx, { product_id: Number(id), supply_id: null, unit_price: p?.cost_price ?? 0 });
                        } else {
                          const s = supplies.find(x => x.id === Number(id));
                          updateLine(idx, { supply_id: Number(id), product_id: null, unit_price: s?.cost_price ?? 0 });
                        }
                      }}>
                      <SelectTrigger><SelectValue placeholder="Select item..." /></SelectTrigger>
                      <SelectContent>
                        {products.length > 0 && <div className="px-2 py-1 text-xs font-semibold text-muted-foreground">Products</div>}
                        {products.map(p => <SelectItem key={`p${p.id}`} value={`p:${p.id}`}>{p.sku} — {p.name}</SelectItem>)}
                        {supplies.length > 0 && <div className="px-2 py-1 text-xs font-semibold text-muted-foreground">Supplies</div>}
                        {supplies.map(s => <SelectItem key={`s${s.id}`} value={`s:${s.id}`}>{s.sku} — {s.name}</SelectItem>)}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="col-span-1 space-y-1">
                    <Label className="text-xs">UoM</Label>
                    <Select value={line.uom_id ? String(line.uom_id) : ''} onValueChange={(v) => updateLine(idx, { uom_id: Number(v) })}>
                      <SelectTrigger><SelectValue placeholder="—" /></SelectTrigger>
                      <SelectContent>
                        {uoms.map(u => <SelectItem key={u.id} value={String(u.id)}>{u.abbreviation}</SelectItem>)}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="col-span-1 space-y-1">
                    <Label className="text-xs">Qty *</Label>
                    <Input type="number" min={1} value={line.quantity_ordered} onChange={e => updateLine(idx, { quantity_ordered: Number(e.target.value) })} />
                  </div>
                  <div className="col-span-2 space-y-1">
                    <Label className="text-xs">Unit Price *</Label>
                    <Input type="number" min={0} step={0.01} value={line.unit_price} onChange={e => updateLine(idx, { unit_price: Number(e.target.value) })} />
                  </div>
                  <div className="col-span-1 space-y-1">
                    <Label className="text-xs">Tax %</Label>
                    <Input type="number" min={0} max={100} step={0.01} value={line.tax_rate} onChange={e => updateLine(idx, { tax_rate: Number(e.target.value) })} />
                  </div>
                  <div className="col-span-1 space-y-1">
                    <Label className="text-xs">Line Total</Label>
                    <p className="px-2 pt-1 text-sm font-medium">₱{(line.quantity_ordered * line.unit_price * (1 + line.tax_rate / 100)).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</p>
                  </div>
                  <div className="col-span-1 flex justify-end">
                    {lines.length > 1 && (
                      <Button type="button" size="icon" variant="ghost" onClick={() => removeLine(idx)}>
                        <Trash2 className="h-4 w-4 text-red-500" />
                      </Button>
                    )}
                  </div>
                </div>
              ))}
              <div className="flex justify-end gap-8 border-t pt-3">
                <div className="text-right">
                  <p className="text-xs text-muted-foreground">Subtotal</p>
                  <p className="text-sm font-medium">₱{subtotal.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</p>
                </div>
                <div className="text-right">
                  <Label className="text-xs">Tax / Other</Label>
                  <Input type="number" min={0} step={0.01} value={taxAmount} onChange={e => setTaxAmount(Number(e.target.value))} className="w-32 text-right" />
                </div>
                <div className="text-right">
                  <p className="text-xs text-muted-foreground">Total</p>
                  <p className="text-2xl font-bold">₱{total.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle className="text-base">Notes</CardTitle></CardHeader>
            <CardContent>
              <Textarea rows={3} value={notes} onChange={e => setNotes(e.target.value)} placeholder="Internal notes, special instructions..." />
            </CardContent>
          </Card>

          <div className="flex justify-end gap-2">
            <Link href="/procurement/orders"><Button type="button" variant="outline">Cancel</Button></Link>
            <Button type="submit" disabled={saving || !supplierId || !warehouseId}>{saving ? 'Saving…' : 'Save as Draft'}</Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
