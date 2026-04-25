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
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { ArrowLeft, PackageCheck } from 'lucide-react';

interface PoItem {
  id: number;
  product?: { id: number; sku: string; name: string };
  quantity_ordered: number;
  quantity_received: number;
  unit_price: number;
}
interface Po {
  id: number;
  po_number: string;
  warehouse_id: number;
  expected_delivery_date?: string;
  supplier?: { id: number; name: string };
  items: PoItem[];
}

interface Location { id: number; code: string; name?: string }
interface Props { po: Po; locations: Location[] }

interface LineState {
  po_item_id: number;
  quantity_received: number;
  quantity_rejected: number;
  condition: 'GOOD' | 'DAMAGED' | 'EXPIRED';
  batch_number: string;
  expiry_date: string;
  rejection_reason: string;
  notes: string;
}

export default function ReceivingCreate({ po, locations }: Props) {
  const outstanding = (it: PoItem) => Math.max(0, it.quantity_ordered - it.quantity_received);

  const [locationId, setLocationId] = useState<number | ''>('');
  const [notes, setNotes] = useState('');
  const [discrepancyNotes, setDiscrepancyNotes] = useState('');
  const [lines, setLines] = useState<LineState[]>(() =>
    po.items.map(it => ({
      po_item_id: it.id,
      quantity_received: outstanding(it),
      quantity_rejected: 0,
      condition: 'GOOD',
      batch_number: '',
      expiry_date: '',
      rejection_reason: '',
      notes: '',
    }))
  );
  const [saving, setSaving] = useState(false);

  function updateLine(idx: number, patch: Partial<LineState>) {
    setLines(ls => ls.map((l, i) => i === idx ? { ...l, ...patch } : l));
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    const payload = {
      po_id: po.id,
      location_id: locationId || null,
      notes,
      discrepancy_notes: discrepancyNotes,
      items: lines.filter(l => l.quantity_received > 0 || l.quantity_rejected > 0),
    };
    router.post('/procurement/receiving', payload as never, {
      onFinish: () => setSaving(false),
    });
  }

  return (
    <AppLayout>
      <Head title={`Receive ${po.po_number}`} />
      <div className="space-y-6 p-6">
        <div className="flex items-center gap-3">
          <Link href={`/procurement/orders/${po.id}`}><Button variant="ghost" size="sm"><ArrowLeft className="mr-1 h-4 w-4" />Back to PO</Button></Link>
          <div>
            <h1 className="text-2xl font-bold">Receive Items</h1>
            <p className="text-sm text-muted-foreground">
              GRN against PO <span className="font-mono">{po.po_number}</span> from <span className="font-medium">{po.supplier?.name}</span>
            </p>
          </div>
        </div>

        <form onSubmit={submit} className="space-y-6">
          <Card>
            <CardHeader><CardTitle className="text-base">Header</CardTitle></CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-1">
                <Label>Storage Location</Label>
                <Select value={locationId ? String(locationId) : ''} onValueChange={(v) => setLocationId(Number(v))}>
                  <SelectTrigger><SelectValue placeholder="(default warehouse)" /></SelectTrigger>
                  <SelectContent>
                    {locations.map(l => <SelectItem key={l.id} value={String(l.id)}>{l.code} {l.name && `— ${l.name}`}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1">
                <Label>Notes</Label>
                <Input value={notes} onChange={e => setNotes(e.target.value)} placeholder="Delivery driver, ref #, etc." />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle className="text-base">Lines — record received quantity per item</CardTitle></CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Item</TableHead>
                    <TableHead className="text-right">Ordered</TableHead>
                    <TableHead className="text-right">Outstanding</TableHead>
                    <TableHead className="text-right w-24">Received *</TableHead>
                    <TableHead className="text-right w-24">Rejected</TableHead>
                    <TableHead className="w-32">Condition</TableHead>
                    <TableHead className="w-36">Batch #</TableHead>
                    <TableHead className="w-40">Expiry</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {po.items.map((it, idx) => {
                    const line = lines[idx];
                    const outQty = outstanding(it);
                    return (
                      <TableRow key={it.id}>
                        <TableCell className="text-sm">
                          {it.product ? <><span className="font-mono">{it.product.sku}</span> — {it.product.name}</> : '—'}
                        </TableCell>
                        <TableCell className="text-right text-sm">{it.quantity_ordered}</TableCell>
                        <TableCell className="text-right text-sm font-medium text-orange-700">{outQty}</TableCell>
                        <TableCell className="text-right">
                          <Input type="number" min={0} max={outQty} value={line.quantity_received}
                            onChange={e => updateLine(idx, { quantity_received: Number(e.target.value) })}
                            className="text-right" />
                        </TableCell>
                        <TableCell className="text-right">
                          <Input type="number" min={0} value={line.quantity_rejected}
                            onChange={e => updateLine(idx, { quantity_rejected: Number(e.target.value) })}
                            className="text-right" />
                        </TableCell>
                        <TableCell>
                          <Select value={line.condition} onValueChange={(v) => updateLine(idx, { condition: v as LineState['condition'] })}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                              <SelectItem value="GOOD">Good</SelectItem>
                              <SelectItem value="DAMAGED">Damaged</SelectItem>
                              <SelectItem value="EXPIRED">Expired</SelectItem>
                            </SelectContent>
                          </Select>
                        </TableCell>
                        <TableCell><Input value={line.batch_number} onChange={e => updateLine(idx, { batch_number: e.target.value })} className="font-mono" /></TableCell>
                        <TableCell><Input type="date" value={line.expiry_date} onChange={e => updateLine(idx, { expiry_date: e.target.value })} /></TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
              <div className="border-t p-4">
                <Label className="text-xs">Discrepancy notes (shortages, condition issues, etc.)</Label>
                <Textarea rows={2} value={discrepancyNotes} onChange={e => setDiscrepancyNotes(e.target.value)} className="mt-1" />
              </div>
            </CardContent>
          </Card>

          <div className="flex items-center justify-end gap-2">
            <Link href={`/procurement/orders/${po.id}`}><Button type="button" variant="outline">Cancel</Button></Link>
            <Button type="submit" disabled={saving}>
              <PackageCheck className="mr-2 h-4 w-4" />
              {saving ? 'Saving…' : 'Save Draft GRN'}
            </Button>
          </div>
          <p className="text-right text-xs text-muted-foreground">A draft GRN is created — confirm it on the next page to post stock.</p>
        </form>
      </div>
    </AppLayout>
  );
}
