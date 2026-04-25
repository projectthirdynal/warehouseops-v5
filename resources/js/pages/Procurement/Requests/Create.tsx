import { useState } from 'react';
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

interface Item { id: number; sku: string; name: string; cost_price: number }
interface Uom  { id: number; name: string; abbreviation: string }

interface LineItem {
  product_id: number | null;
  supply_id: number | null;
  uom_id: number | null;
  quantity_requested: number;
  unit_price_estimate: number;
  notes: string;
}

interface Props {
  products: Item[];
  supplies: Item[];
  uoms: Uom[];
}

const blankLine = (): LineItem => ({
  product_id: null, supply_id: null, uom_id: null,
  quantity_requested: 1, unit_price_estimate: 0, notes: '',
});

export default function PrCreate({ products, supplies, uoms }: Props) {
  const [department, setDepartment] = useState('');
  const [priority, setPriority] = useState('NORMAL');
  const [reason, setReason] = useState('');
  const [neededBy, setNeededBy] = useState('');
  const [lines, setLines] = useState<LineItem[]>([blankLine()]);
  const [saving, setSaving] = useState(false);

  function updateLine(idx: number, patch: Partial<LineItem>) {
    setLines(ls => ls.map((l, i) => i === idx ? { ...l, ...patch } : l));
  }
  function addLine()       { setLines(ls => [...ls, blankLine()]); }
  function removeLine(i: number) { setLines(ls => ls.filter((_, x) => x !== i)); }

  const total = lines.reduce((s, l) => s + (l.quantity_requested * l.unit_price_estimate), 0);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    const payload = {
      department, priority, reason, needed_by_date: neededBy || null,
      items: lines.filter(l => l.product_id || l.supply_id),
    };
    router.post('/procurement/requests', payload as never, {
      onFinish: () => setSaving(false),
    });
  }

  return (
    <AppLayout>
      <Head title="New Purchase Request" />
      <div className="space-y-6 p-6">
        <div className="flex items-center gap-3">
          <Link href="/procurement/requests"><Button variant="ghost" size="sm"><ArrowLeft className="mr-1 h-4 w-4" />Back</Button></Link>
          <div>
            <h1 className="text-2xl font-bold">New Purchase Request</h1>
            <p className="text-sm text-muted-foreground">Submit a request for procurement.</p>
          </div>
        </div>

        <form onSubmit={submit} className="space-y-6">
          <Card>
            <CardHeader><CardTitle className="text-base">Header</CardTitle></CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-1">
                <Label>Department</Label>
                <Input value={department} onChange={e => setDepartment(e.target.value)} placeholder="Warehouse, Marketing..." />
              </div>
              <div className="space-y-1">
                <Label>Priority *</Label>
                <Select value={priority} onValueChange={setPriority}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="LOW">Low</SelectItem>
                    <SelectItem value="NORMAL">Normal</SelectItem>
                    <SelectItem value="URGENT">Urgent</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1">
                <Label>Needed By</Label>
                <Input type="date" value={neededBy} onChange={e => setNeededBy(e.target.value)} />
              </div>
              <div className="space-y-1 sm:col-span-2">
                <Label>Reason / Justification</Label>
                <Textarea rows={3} value={reason} onChange={e => setReason(e.target.value)} />
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
                  <div className="col-span-4 space-y-1">
                    <Label className="text-xs">Item *</Label>
                    <Select
                      value={line.product_id ? `p:${line.product_id}` : line.supply_id ? `s:${line.supply_id}` : ''}
                      onValueChange={(v) => {
                        const [type, id] = v.split(':');
                        if (type === 'p') {
                          const p = products.find(x => x.id === Number(id));
                          updateLine(idx, { product_id: Number(id), supply_id: null, unit_price_estimate: p?.cost_price ?? 0 });
                        } else {
                          const s = supplies.find(x => x.id === Number(id));
                          updateLine(idx, { supply_id: Number(id), product_id: null, unit_price_estimate: s?.cost_price ?? 0 });
                        }
                      }}>
                      <SelectTrigger><SelectValue placeholder="Select product or supply..." /></SelectTrigger>
                      <SelectContent>
                        {products.length > 0 && <div className="px-2 py-1 text-xs font-semibold text-muted-foreground">Products</div>}
                        {products.map(p => <SelectItem key={`p${p.id}`} value={`p:${p.id}`}>{p.sku} — {p.name}</SelectItem>)}
                        {supplies.length > 0 && <div className="px-2 py-1 text-xs font-semibold text-muted-foreground">Supplies</div>}
                        {supplies.map(s => <SelectItem key={`s${s.id}`} value={`s:${s.id}`}>{s.sku} — {s.name}</SelectItem>)}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="col-span-2 space-y-1">
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
                    <Input type="number" min={1} value={line.quantity_requested} onChange={e => updateLine(idx, { quantity_requested: Number(e.target.value) })} />
                  </div>
                  <div className="col-span-2 space-y-1">
                    <Label className="text-xs">Est. Unit ₱</Label>
                    <Input type="number" min={0} step={0.01} value={line.unit_price_estimate} onChange={e => updateLine(idx, { unit_price_estimate: Number(e.target.value) })} />
                  </div>
                  <div className="col-span-2 space-y-1">
                    <Label className="text-xs">Notes</Label>
                    <Input value={line.notes} onChange={e => updateLine(idx, { notes: e.target.value })} />
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
              <div className="flex justify-end pt-2">
                <div className="text-right">
                  <p className="text-xs text-muted-foreground">Estimated total</p>
                  <p className="text-2xl font-bold">₱{total.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-2">
            <Link href="/procurement/requests"><Button type="button" variant="outline">Cancel</Button></Link>
            <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save as Draft'}</Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
