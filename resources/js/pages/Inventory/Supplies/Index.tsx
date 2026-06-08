import { useEffect, useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { AlertTriangle, Boxes, Edit2, PackagePlus, Plus, Search, SlidersHorizontal } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface Uom {
  id: number;
  name: string;
  abbreviation: string;
}

interface Warehouse {
  id: number;
  name: string;
  code: string;
}

interface SupplyStock {
  id: number;
  current_stock: number;
  reserved_stock: number;
  available_stock: number;
  reorder_point: number;
  warehouse?: Warehouse;
}

interface Supply {
  id: number;
  sku: string;
  name: string;
  category?: string;
  uom_id?: number;
  uom?: Uom;
  cost_price: number;
  min_stock_level: number;
  reorder_point: number;
  description?: string;
  is_active: boolean;
  stocks: SupplyStock[];
}

interface SupplyMovement {
  id: number;
  type: string;
  quantity: number;
  batch_number?: string;
  notes?: string;
  created_at: string;
  supply?: { id: number; sku: string; name: string };
  warehouse?: Warehouse;
  performer?: { id: number; name: string };
}

interface Props {
  supplies: PaginatedResponse<Supply>;
  stats: {
    total: number;
    active: number;
    low_stock: number;
    categories: string[];
  };
  filters: { search?: string; category?: string; status?: string };
  uoms: Uom[];
  warehouses: Warehouse[];
  recent_movements: SupplyMovement[];
}

export default function SuppliesIndex({ supplies, stats, filters, uoms, warehouses, recent_movements }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [materialOpen, setMaterialOpen] = useState(false);
  const [editing, setEditing] = useState<Supply | null>(null);
  const [stockTarget, setStockTarget] = useState<Supply | null>(null);

  function applyFilters(overrides: Record<string, string>) {
    router.get('/inventory/supplies', { ...filters, ...overrides }, { preserveState: true, replace: true });
  }

  const stockValue = useMemo(() =>
    supplies.data.reduce((sum, supply) => sum + totalStock(supply) * Number(supply.cost_price), 0),
    [supplies.data]
  );

  return (
    <AppLayout>
      <Head title="Materials" />
      <div className="space-y-6 p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold">Materials</h1>
            <p className="text-sm text-muted-foreground">Warehouse assets used to make or pack products, such as bottles, caps, boxes, labels, and inserts.</p>
          </div>
          <Button onClick={() => { setEditing(null); setMaterialOpen(true); }}>
            <Plus className="mr-2 h-4 w-4" />New Material
          </Button>
        </div>

        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          <StatCard label="Materials" value={stats.total} />
          <StatCard label="Active" value={stats.active} />
          <StatCard label="Low Stock" value={stats.low_stock} tone="warn" />
          <StatCard label="Page Stock Value" value={formatCurrency(stockValue)} />
        </div>

        <Card>
          <CardContent className="flex flex-wrap items-center gap-3 p-4">
            <form onSubmit={(e) => { e.preventDefault(); applyFilters({ search, page: '1' }); }} className="flex min-w-64 flex-1 gap-2">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input className="pl-9" placeholder="Search SKU or material name..." value={search} onChange={e => setSearch(e.target.value)} />
              </div>
              <Button type="submit" variant="secondary">Search</Button>
            </form>

            <Select value={filters.category ?? 'all'} onValueChange={(v) => applyFilters({ category: v === 'all' ? '' : v, page: '1' })}>
              <SelectTrigger className="w-44"><SelectValue placeholder="Category" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Categories</SelectItem>
                {stats.categories.map(category => <SelectItem key={category} value={category}>{category}</SelectItem>)}
              </SelectContent>
            </Select>

            <Select value={filters.status ?? 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v, page: '1' })}>
              <SelectTrigger className="w-36"><SelectValue placeholder="Status" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>
          </CardContent>
        </Card>

        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Material</TableHead>
                <TableHead>Category</TableHead>
                <TableHead className="text-right">Available</TableHead>
                <TableHead className="text-right">Reorder</TableHead>
                <TableHead className="text-right">Unit Cost</TableHead>
                <TableHead>Warehouse</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-24"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {supplies.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={8} className="py-12 text-center text-muted-foreground">
                    <Boxes className="mx-auto mb-2 h-8 w-8 opacity-30" />
                    No materials yet. Add bottles, labels, packaging, or other supplies here.
                  </TableCell>
                </TableRow>
              ) : supplies.data.map(supply => {
                const available = totalAvailable(supply);
                const reorder = supply.stocks[0]?.reorder_point ?? supply.reorder_point;
                const isLow = reorder > 0 && available <= reorder;

                return (
                  <TableRow key={supply.id}>
                    <TableCell>
                      <div className="font-medium">{supply.name}</div>
                      <div className="text-xs text-muted-foreground"><span className="font-mono">{supply.sku}</span>{supply.uom && ` / ${supply.uom.abbreviation}`}</div>
                    </TableCell>
                    <TableCell className="text-sm">{supply.category ?? '—'}</TableCell>
                    <TableCell className={`text-right font-medium ${isLow ? 'text-orange-700' : ''}`}>
                      {available}
                      {isLow && <AlertTriangle className="ml-1 inline h-3.5 w-3.5" />}
                    </TableCell>
                    <TableCell className="text-right text-sm">{reorder}</TableCell>
                    <TableCell className="text-right text-sm">{formatCurrency(Number(supply.cost_price))}</TableCell>
                    <TableCell className="text-sm">{supply.stocks.map(stock => stock.warehouse?.name).filter(Boolean).join(', ') || '—'}</TableCell>
                    <TableCell>
                      <span className={`rounded-full px-2 py-0.5 text-xs ${supply.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'}`}>
                        {supply.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </TableCell>
                    <TableCell>
                      <div className="flex justify-end gap-1">
                        <Button size="icon" variant="ghost" onClick={() => setStockTarget(supply)}><SlidersHorizontal className="h-4 w-4" /></Button>
                        <Button size="icon" variant="ghost" onClick={() => { setEditing(supply); setMaterialOpen(true); }}><Edit2 className="h-4 w-4" /></Button>
                      </div>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Card>

        {recent_movements.length > 0 && (
          <Card>
            <CardContent className="p-0">
              <div className="border-b px-4 py-3 text-sm font-medium">Recent Material Movements</div>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>When</TableHead>
                    <TableHead>Material</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead className="text-right">Qty</TableHead>
                    <TableHead>Warehouse</TableHead>
                    <TableHead>Notes</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {recent_movements.map(movement => (
                    <TableRow key={movement.id}>
                      <TableCell className="text-xs text-muted-foreground">{formatDate(movement.created_at)}</TableCell>
                      <TableCell className="text-sm">{movement.supply ? <><span className="font-mono">{movement.supply.sku}</span> — {movement.supply.name}</> : '—'}</TableCell>
                      <TableCell><span className={`rounded px-2 py-0.5 text-xs ${movement.quantity < 0 ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'}`}>{movement.type}</span></TableCell>
                      <TableCell className={`text-right font-medium ${movement.quantity < 0 ? 'text-red-600' : 'text-green-600'}`}>{movement.quantity > 0 ? `+${movement.quantity}` : movement.quantity}</TableCell>
                      <TableCell className="text-sm">{movement.warehouse?.name ?? '—'}</TableCell>
                      <TableCell className="max-w-xs truncate text-xs text-muted-foreground">{movement.notes ?? ''}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}
      </div>

      <MaterialDialog open={materialOpen} onClose={() => setMaterialOpen(false)} editing={editing} uoms={uoms} warehouses={warehouses} categories={stats.categories} />
      <StockDialog supply={stockTarget} onClose={() => setStockTarget(null)} warehouses={warehouses} />
    </AppLayout>
  );
}

function MaterialDialog({ open, onClose, editing, uoms, warehouses, categories }: {
  open: boolean;
  onClose: () => void;
  editing: Supply | null;
  uoms: Uom[];
  warehouses: Warehouse[];
  categories: string[];
}) {
  const form = useForm({
    sku: '',
    name: '',
    category: '',
    uom_id: '',
    cost_price: 0,
    min_stock_level: 0,
    reorder_point: 10,
    description: '',
    is_active: true,
    initial_stock: 0,
    warehouse_id: warehouses[0]?.id ? String(warehouses[0].id) : '',
  });

  useEffect(() => {
    form.setData({
      sku: editing?.sku ?? '',
      name: editing?.name ?? '',
      category: editing?.category ?? '',
      uom_id: editing?.uom_id ? String(editing.uom_id) : '',
      cost_price: Number(editing?.cost_price ?? 0),
      min_stock_level: editing?.min_stock_level ?? 0,
      reorder_point: editing?.reorder_point ?? 10,
      description: editing?.description ?? '',
      is_active: editing?.is_active ?? true,
      initial_stock: 0,
      warehouse_id: warehouses[0]?.id ? String(warehouses[0].id) : '',
    });
    form.clearErrors();
  }, [editing, open]);

  function submit(e: React.FormEvent) {
    e.preventDefault();

    if (editing) {
      form.put(`/inventory/supplies/${editing.id}`, { onSuccess: onClose });
    } else {
      form.post('/inventory/supplies', { onSuccess: () => { onClose(); form.reset(); } });
    }
  }

  return (
    <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
      <DialogContent>
        <DialogHeader><DialogTitle>{editing ? 'Edit Material' : 'New Material'}</DialogTitle></DialogHeader>
        <form onSubmit={submit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1"><Label>SKU *</Label><Input value={form.data.sku} onChange={e => form.setData('sku', e.target.value.toUpperCase())} required className="font-mono uppercase" /></div>
            <div className="space-y-1"><Label>Name *</Label><Input value={form.data.name} onChange={e => form.setData('name', e.target.value)} required placeholder="Bottles, caps, labels..." /></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label>Category</Label>
              <Input
                value={form.data.category}
                onChange={e => form.setData('category', e.target.value)}
                placeholder="Packaging, Raw Material..."
                list="category-suggestions"
              />
              <datalist id="category-suggestions">
                {categories.map(c => <option key={c} value={c} />)}
              </datalist>
            </div>
            <div className="space-y-1">
              <Label>UoM</Label>
              <Select value={form.data.uom_id || 'none'} onValueChange={(value) => form.setData('uom_id', value === 'none' ? '' : value)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None</SelectItem>
                  {uoms.map(uom => <SelectItem key={uom.id} value={String(uom.id)}>{uom.name} ({uom.abbreviation})</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div className="space-y-1"><Label>Unit Cost *</Label><Input type="number" min={0} step="0.0001" value={form.data.cost_price} onChange={e => form.setData('cost_price', Number(e.target.value))} required /></div>
            <div className="space-y-1"><Label>Min Stock</Label><Input type="number" min={0} value={form.data.min_stock_level} onChange={e => form.setData('min_stock_level', Number(e.target.value))} /></div>
            <div className="space-y-1"><Label>Reorder Point</Label><Input type="number" min={0} value={form.data.reorder_point} onChange={e => form.setData('reorder_point', Number(e.target.value))} /></div>
          </div>
          {!editing && (
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1"><Label>Initial Stock</Label><Input type="number" min={0} value={form.data.initial_stock} onChange={e => form.setData('initial_stock', Number(e.target.value))} /></div>
              <div className="space-y-1">
                <Label>Warehouse</Label>
                <Select value={form.data.warehouse_id || 'default'} onValueChange={(value) => form.setData('warehouse_id', value === 'default' ? '' : value)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="default">Default warehouse</SelectItem>
                    {warehouses.map(warehouse => <SelectItem key={warehouse.id} value={String(warehouse.id)}>{warehouse.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
            </div>
          )}
          <div className="space-y-1"><Label>Description</Label><Textarea rows={2} value={form.data.description} onChange={e => form.setData('description', e.target.value)} /></div>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form.data.is_active} onChange={e => form.setData('is_active', e.target.checked)} />Active
          </label>
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={form.processing}>{editing ? 'Save' : 'Create'}</Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function StockDialog({ supply, onClose, warehouses }: { supply: Supply | null; onClose: () => void; warehouses: Warehouse[] }) {
  const form = useForm({
    type: 'stock_in',
    quantity: 1,
    warehouse_id: warehouses[0]?.id ? String(warehouses[0].id) : '',
    notes: '',
  });

  useEffect(() => {
    form.setData({
      type: 'stock_in',
      quantity: 1,
      warehouse_id: warehouses[0]?.id ? String(warehouses[0].id) : '',
      notes: '',
    });
    form.clearErrors();
  }, [supply?.id]);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!supply) return;

    form.post(`/inventory/supplies/${supply.id}/stock`, { onSuccess: onClose });
  }

  return (
    <Dialog open={!!supply} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader><DialogTitle>Adjust Material Stock</DialogTitle></DialogHeader>
        {supply && (
          <form onSubmit={submit} className="space-y-3">
            <div className="rounded-md bg-muted p-3 text-sm">
              <div className="font-medium">{supply.name}</div>
              <div className="font-mono text-xs text-muted-foreground">{supply.sku}</div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label>Action</Label>
                <Select value={form.data.type} onValueChange={(value) => form.setData('type', value)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="stock_in">Stock In</SelectItem>
                    <SelectItem value="stock_out">Stock Out</SelectItem>
                    <SelectItem value="adjustment">Set Exact Qty</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1"><Label>{form.data.type === 'adjustment' ? 'New Qty' : 'Quantity'}</Label><Input type="number" min={1} value={form.data.quantity} onChange={e => form.setData('quantity', Number(e.target.value))} /></div>
            </div>
            <div className="space-y-1">
              <Label>Warehouse</Label>
              <Select value={form.data.warehouse_id || 'default'} onValueChange={(value) => form.setData('warehouse_id', value === 'default' ? '' : value)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="default">Default warehouse</SelectItem>
                  {warehouses.map(warehouse => <SelectItem key={warehouse.id} value={String(warehouse.id)}>{warehouse.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1"><Label>Notes</Label><Input value={form.data.notes} onChange={e => form.setData('notes', e.target.value)} /></div>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
              <Button type="submit" disabled={form.processing}><PackagePlus className="mr-2 h-4 w-4" />Post</Button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}

function StatCard({ label, value, tone }: { label: string; value: string | number; tone?: 'warn' }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className={`mt-1 text-2xl font-bold ${tone === 'warn' ? 'text-orange-700' : ''}`}>{value}</p>
      </CardContent>
    </Card>
  );
}

function totalStock(supply: Supply): number {
  return supply.stocks.reduce((sum, stock) => sum + Number(stock.current_stock), 0);
}

function totalAvailable(supply: Supply): number {
  return supply.stocks.reduce((sum, stock) => sum + Number(stock.available_stock ?? stock.current_stock - stock.reserved_stock), 0);
}
