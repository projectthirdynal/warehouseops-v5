import { useEffect, useState } from 'react';
import { Drawer } from 'vaul';
import { AlertTriangle, Box, Edit2, SlidersHorizontal, Tag, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/utils';
import { ActivityTimeline } from '@/components/ActivityTimeline';

interface SupplySummary {
  supply: {
    id: number;
    sku: string;
    name: string;
    category: string | null;
    section: string | null;
    stock_category: string | null;
    opex_category: string | null;
    cost_price: string | number;
    reorder_point: number;
    stock_status: string;
    is_active: boolean;
    description: string | null;
    uom: { id: number; name: string; abbreviation: string } | null;
  };
  stocks: {
    id: number;
    warehouse_name: string | null;
    warehouse_code: string | null;
    current_stock: number;
    reserved_stock: number;
    available: number;
    reorder_point: number;
  }[];
  kpi: {
    total_stock: number;
    reserved_stock: number;
    available_stock: number;
    reorder_point: number;
  };
  recent_movements: {
    id: number;
    type: string;
    quantity: number;
    notes: string | null;
    created_at: string;
    warehouse_name: string | null;
    performer_name: string | null;
  }[];
}

interface Props {
  supplyId: number | null;
  onClose: () => void;
  onEdit: (id: number) => void;
  onAdjustStock: (id: number) => void;
  onOverrideStatus: (id: number) => void;
}

const STATUS_BADGES: Record<string, string> = {
  MOVING:       'bg-emerald-950/40 text-emerald-300 border-emerald-800',
  NON_MOVING:   'bg-amber-950/40 text-amber-300 border-amber-800',
  DEAD:         'bg-red-950/40 text-red-300 border-red-800',
  OUT_OF_STOCK: 'bg-slate-800 text-slate-400 border-slate-700',
};

export function SupplyDetailDrawer({ supplyId, onClose, onEdit, onAdjustStock, onOverrideStatus }: Props) {
  const [data, setData] = useState<SupplySummary | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!supplyId) { setData(null); return; }
    setLoading(true);
    fetch(`/inventory/supplies/${supplyId}/summary`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    })
      .then(r => r.json())
      .then((d: SupplySummary) => { setData(d); setLoading(false); })
      .catch(() => setLoading(false));
  }, [supplyId]);

  const isLow = data
    ? data.kpi.reorder_point > 0 && data.kpi.available_stock <= data.kpi.reorder_point
    : false;

  return (
    <Drawer.Root open={!!supplyId} onOpenChange={(open) => { if (!open) onClose(); }} direction="right">
      <Drawer.Portal>
        <Drawer.Overlay className="fixed inset-0 z-40 bg-black/50" />
        <Drawer.Content
          className="fixed right-0 top-0 bottom-0 z-50 flex w-full max-w-md flex-col bg-background shadow-xl outline-none"
        >
          <Drawer.Title className="sr-only">
            {loading ? 'Material Detail' : (data?.supply.name ?? 'Material Detail')}
          </Drawer.Title>
          <Drawer.Description className="sr-only">
            Material details including stock levels, warehouse breakdown, and recent movements.
          </Drawer.Description>
          {/* Header */}
          <div className="flex items-center justify-between border-b px-5 py-4">
            <div className="flex items-center gap-2">
              <Box className="h-4 w-4 text-purple-400" />
              <span className="text-sm font-semibold">
                {loading ? 'Loading…' : (data?.supply.name ?? 'Material Detail')}
              </span>
            </div>
            <Button size="icon" variant="ghost" onClick={onClose} className="h-7 w-7">
              <X className="h-4 w-4" />
            </Button>
          </div>

          {loading && (
            <div className="flex flex-1 items-center justify-center text-sm text-muted-foreground">
              Loading…
            </div>
          )}

          {!loading && data && (
            <div className="flex-1 overflow-y-auto">
              {/* Quick action bar */}
              <div className="flex gap-2 border-b px-5 py-3">
                <Button size="sm" variant="outline" className="h-7 gap-1 text-xs" onClick={() => onEdit(data.supply.id)}>
                  <Edit2 className="h-3 w-3" /> Edit
                </Button>
                <Button size="sm" variant="outline" className="h-7 gap-1 text-xs" onClick={() => onAdjustStock(data.supply.id)}>
                  <SlidersHorizontal className="h-3 w-3" /> Adjust Stock
                </Button>
                <Button size="sm" variant="outline" className="h-7 gap-1 text-xs" onClick={() => onOverrideStatus(data.supply.id)}>
                  <Tag className="h-3 w-3" /> Override Status
                </Button>
              </div>

              <div className="space-y-5 p-5">
                {/* Identity */}
                <section>
                  <div className="mb-2 flex items-center justify-between">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Identity</h3>
                    <span className={`rounded-full border px-2 py-0.5 text-[11px] font-medium ${STATUS_BADGES[data.supply.stock_status] ?? 'bg-muted text-muted-foreground border-border'}`}>
                      {data.supply.stock_status?.replace('_', ' ')}
                    </span>
                  </div>
                  <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div>
                      <dt className="text-[11px] text-muted-foreground">SKU</dt>
                      <dd className="font-mono font-medium">{data.supply.sku}</dd>
                    </div>
                    <div>
                      <dt className="text-[11px] text-muted-foreground">UoM</dt>
                      <dd>{data.supply.uom ? `${data.supply.uom.name} (${data.supply.uom.abbreviation})` : '—'}</dd>
                    </div>
                    <div>
                      <dt className="text-[11px] text-muted-foreground">Section</dt>
                      <dd>{data.supply.section ?? '—'}</dd>
                    </div>
                    <div>
                      <dt className="text-[11px] text-muted-foreground">Category</dt>
                      <dd>{data.supply.category ?? '—'}</dd>
                    </div>
                    <div>
                      <dt className="text-[11px] text-muted-foreground">Unit Cost</dt>
                      <dd className="font-medium">{formatCurrency(Number(data.supply.cost_price))}</dd>
                    </div>
                    <div>
                      <dt className="text-[11px] text-muted-foreground">Status</dt>
                      <dd>
                        <span className={`rounded-full px-2 py-0.5 text-xs ${data.supply.is_active ? 'bg-green-950/40 text-green-300' : 'bg-slate-800 text-slate-400'}`}>
                          {data.supply.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </dd>
                    </div>
                  </dl>
                  {data.supply.description && (
                    <p className="mt-2 text-xs text-muted-foreground">{data.supply.description}</p>
                  )}
                </section>

                {/* KPI strip */}
                <section>
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Stock Summary</h3>
                  <div className="grid grid-cols-3 gap-2">
                    {[
                      { label: 'Available', value: data.kpi.available_stock, alert: isLow },
                      { label: 'Reserved',  value: data.kpi.reserved_stock,  alert: false },
                      { label: 'Reorder At', value: data.kpi.reorder_point,  alert: false },
                    ].map(({ label, value, alert }) => (
                      <div key={label} className="rounded-md border bg-muted/30 p-2.5 text-center">
                        <div className={`text-xl font-bold tabular-nums ${alert ? 'text-orange-400' : ''}`}>
                          {value.toLocaleString()}
                          {alert && <AlertTriangle className="ml-1 inline h-3.5 w-3.5 text-orange-400" />}
                        </div>
                        <div className="text-[11px] text-muted-foreground">{label}</div>
                      </div>
                    ))}
                  </div>
                </section>

                {/* Per-warehouse breakdown */}
                {data.stocks.length > 0 && (
                  <section>
                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Warehouses</h3>
                    <div className="divide-y divide-border rounded-md border">
                      {data.stocks.map(s => (
                        <div key={s.id} className="flex items-center justify-between px-3 py-2 text-sm">
                          <div>
                            <div className="font-medium">{s.warehouse_name ?? 'Unknown'}</div>
                            <div className="text-[11px] font-mono text-muted-foreground">{s.warehouse_code}</div>
                          </div>
                          <div className="text-right">
                            <div className="font-semibold tabular-nums">{s.available.toLocaleString()}</div>
                            <div className="text-[11px] text-muted-foreground">avail / {s.current_stock} total</div>
                          </div>
                        </div>
                      ))}
                    </div>
                  </section>
                )}

                {/* Recent movements */}
                <section>
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Recent Movements</h3>
                  <ActivityTimeline
                    events={data.recent_movements.map(m => ({
                      id: m.id,
                      type: m.type,
                      quantity: m.quantity,
                      notes: m.notes,
                      created_at: m.created_at,
                      warehouse_name: m.warehouse_name,
                      performer_name: m.performer_name,
                    }))}
                    emptyMessage="No movements recorded."
                  />
                </section>
              </div>
            </div>
          )}
        </Drawer.Content>
      </Drawer.Portal>
    </Drawer.Root>
  );
}
