import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Truck, CheckCircle, Plus } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface Settlement {
  id: number;
  courier_code: string;
  reference_number: string | null;
  period_start: string;
  period_end: string;
  total_cod_collected: number;
  courier_fee: number;
  net_amount: number;
  order_count: number;
  status: 'PENDING' | 'RECEIVED' | 'RECONCILED';
  received_at: string | null;
  notes: string | null;
  created_at: string;
}

interface Props {
  settlements: PaginatedResponse<Settlement>;
  stats: { pending_amount: number; received_amount: number; total_collected: number };
}

const statusCfg: Record<string, { label: string; color: string }> = {
  PENDING:    { label: 'Pending',    color: 'bg-yellow-100 text-yellow-800' },
  RECEIVED:   { label: 'Received',   color: 'bg-green-100 text-green-800' },
  RECONCILED: { label: 'Reconciled', color: 'bg-blue-100 text-blue-800' },
};

export default function CodSettlements({ settlements, stats }: Props) {
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({
    courier_code: 'FLASH', reference_number: '', period_start: '', period_end: '',
    total_cod_collected: '', courier_fee: '', order_count: '', notes: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    router.post('/finance/cod', {
      ...form,
      total_cod_collected: parseFloat(form.total_cod_collected) || 0,
      courier_fee: parseFloat(form.courier_fee) || 0,
      order_count: parseInt(form.order_count) || 0,
    }, { preserveScroll: true, onSuccess: () => { setShowForm(false); setForm({ courier_code: 'FLASH', reference_number: '', period_start: '', period_end: '', total_cod_collected: '', courier_fee: '', order_count: '', notes: '' }); } });
  };

  const handleReceive = (id: number) => {
    router.post(`/finance/cod/${id}/receive`, {}, { preserveScroll: true });
  };

  return (
    <AppLayout>
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">COD Settlements</h1>
            <p className="text-sm text-muted-foreground">Track COD remittances from couriers</p>
          </div>
          <Button onClick={() => setShowForm(!showForm)}>
            <Plus className="mr-2 h-4 w-4" />{showForm ? 'Cancel' : 'Record Settlement'}
          </Button>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-3 gap-4">
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-yellow-600">{formatCurrency(stats.pending_amount)}</p>
              <p className="text-xs text-muted-foreground">Pending</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-green-600">{formatCurrency(stats.received_amount)}</p>
              <p className="text-xs text-muted-foreground">Received</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold">{formatCurrency(stats.total_collected)}</p>
              <p className="text-xs text-muted-foreground">Total COD Collected</p>
            </CardContent>
          </Card>
        </div>

        {/* New settlement form */}
        {showForm && (
          <Card>
            <CardHeader><CardTitle className="text-base">Record New Settlement</CardTitle></CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Courier</label>
                  <select value={form.courier_code} onChange={(e) => setForm((p) => ({ ...p, courier_code: e.target.value }))} className="w-full border rounded-lg px-3 py-2 text-sm">
                    <option value="FLASH">Flash Express</option>
                    <option value="JNT">J&T Express</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Reference Number</label>
                  <input type="text" value={form.reference_number} onChange={(e) => setForm((p) => ({ ...p, reference_number: e.target.value }))} className="w-full border rounded-lg px-3 py-2 text-sm" placeholder="Settlement ref #" />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Period Start</label>
                  <input type="date" value={form.period_start} onChange={(e) => setForm((p) => ({ ...p, period_start: e.target.value }))} className="w-full border rounded-lg px-3 py-2 text-sm" required />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Period End</label>
                  <input type="date" value={form.period_end} onChange={(e) => setForm((p) => ({ ...p, period_end: e.target.value }))} className="w-full border rounded-lg px-3 py-2 text-sm" required />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Total COD Collected (PHP)</label>
                  <input type="number" step="0.01" value={form.total_cod_collected} onChange={(e) => setForm((p) => ({ ...p, total_cod_collected: e.target.value }))} className="w-full border rounded-lg px-3 py-2 text-sm" required />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Courier Fee (PHP)</label>
                  <input type="number" step="0.01" value={form.courier_fee} onChange={(e) => setForm((p) => ({ ...p, courier_fee: e.target.value }))} className="w-full border rounded-lg px-3 py-2 text-sm" required />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Order Count</label>
                  <input type="number" value={form.order_count} onChange={(e) => setForm((p) => ({ ...p, order_count: e.target.value }))} className="w-full border rounded-lg px-3 py-2 text-sm" required />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Notes</label>
                  <input type="text" value={form.notes} onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))} className="w-full border rounded-lg px-3 py-2 text-sm" />
                </div>
                <div className="col-span-2">
                  <Button type="submit" className="w-full">Record Settlement</Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}

        {/* Settlement list */}
        <div className="space-y-2">
          {settlements.data.length === 0 ? (
            <Card>
              <CardContent className="py-12 text-center text-muted-foreground">
                <Truck className="h-10 w-10 mx-auto mb-3 opacity-30" />
                <p>No COD settlements recorded yet.</p>
              </CardContent>
            </Card>
          ) : (
            settlements.data.map((s) => {
              const cfg = statusCfg[s.status] ?? statusCfg.PENDING;
              return (
                <Card key={s.id}>
                  <CardContent className="p-4">
                    <div className="flex items-center gap-4">
                      <div className="flex-1">
                        <div className="flex items-center gap-2">
                          <Badge variant="outline">{s.courier_code}</Badge>
                          <Badge className={`${cfg.color} text-[10px]`}>{cfg.label}</Badge>
                          {s.reference_number && <span className="text-xs text-muted-foreground font-mono">{s.reference_number}</span>}
                        </div>
                        <p className="text-xs text-muted-foreground mt-1">
                          {formatDate(s.period_start)} — {formatDate(s.period_end)} | {s.order_count} orders
                        </p>
                      </div>
                      <div className="text-right shrink-0">
                        <p className="font-semibold">{formatCurrency(s.net_amount)}</p>
                        <p className="text-xs text-muted-foreground">
                          COD: {formatCurrency(s.total_cod_collected)} | Fee: {formatCurrency(s.courier_fee)}
                        </p>
                      </div>
                      {s.status === 'PENDING' && (
                        <Button variant="outline" size="sm" onClick={() => handleReceive(s.id)}>
                          <CheckCircle className="mr-1 h-3.5 w-3.5" />Received
                        </Button>
                      )}
                    </div>
                  </CardContent>
                </Card>
              );
            })
          )}
        </div>
      </div>
    </AppLayout>
  );
}
