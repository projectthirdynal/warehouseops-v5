import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
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
import { AlertTriangle, Boxes, Edit2, PackagePlus, Plus, Search, SlidersHorizontal, Tag, Trash2 } from 'lucide-react';
import Paginator from '@/components/Paginator';
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
  section: 'STOCK' | 'OPEX';
  stock_category?: string;
  opex_category?: string;
  stock_status: 'MOVING' | 'NON_MOVING' | 'DEAD';
  stock_status_override: boolean;
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
    trashed: number;
    by_stock_status: { MOVING: number; NON_MOVING: number; DEAD: number; OUT_OF_STOCK: number };
    categories: string[];
  };
  filters: {
    search?: string;
    category?: string;
    status?: string;
    stock_category?: string;
    opex_category?: string;
    stock_status?: string;
  };
  uoms: Uom[];
  warehouses: Warehouse[];
  recent_movements: SupplyMovement[];
}

const STOCK_CATEGORY_TABS = [
  { value: 'all',                label: 'All' },
  { value: 'RAW_MATERIAL',       label: 'Raw Materials' },
  { value: 'PRODUCTION_MATERIAL',label: 'Production' },
  { value: 'MERCHANDISE',        label: 'Merchandise' },
  { value: 'RD_SUPPLY',          label: 'R&D' },
];

const OPEX_CATEGORY_TABS = [
  { value: 'all',               label: 'All' },
  { value: 'OFFICE_SUPPLY',     label: 'Office Supplies' },
  { value: 'CLEANING_MATERIAL', label: 'Cleaning' },
];

const STATUS_TABS = [
  { value: 'all',          label: 'All' },
  { value: 'MOVING',       label: 'Moving' },
  { value: 'NON_MOVING',   label: 'Non-Moving' },
  { value: 'DEAD',         label: 'Dead Stock' },
  { value: 'OUT_OF_STOCK', label: 'Out of Stock' },
];

export default function SuppliesIndex({ supplies, stats, filters, uoms, warehouses, recent_movements }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [materialOpen, setMaterialOpen] = useState(false);
  const [editing, setEditing] = useState<Supply | null>(null);
  const [stockTarget, setStockTarget] = useState<Supply | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Supply | null>(null);
  const [statusTarget, setStatusTarget] = useState<Supply | null>(null);

  const stockStatus  = filters.stock_status ?? 'all';

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
        {/* Header */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold">Materials</h1>
            <p className="text-sm text-muted-foreground">Stock, OPEX, and asset items across all warehouses.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Link href="/inventory/assets">
              <Button variant="outline" size="sm">CAPEX Assets</Button>
            </Link>
            {stats.trashed > 0 && (
              <Link href="/inventory/supplies/trash">
                <Button variant="outline" size="sm" className="text-red-600 border-red-200 hover:bg-red-50">
                  <Trash2 className="mr-1.5 h-3.5 w-3.5" />Trash ({stats.trashed})
                </Button>
              </Link>
            )}
            <Button onClick={() => { setEditing(null); setMaterialOpen(true); }}>
              <Plus className="mr-2 h-4 w-4" />New Material
            </Button>
          </div>
        </div>

        {/* KPI cards */}
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          <StatCard label="Materials" value={stats.total} />
          <StatCard label="Active" value={stats.active} />
          <StatCard label="Low Stock" value={stats.low_stock} tone="warn" />
          <StatCard label="Page Stock Value" value={formatCurrency(stockValue)} />
        </div>

        {/* Stock category tabs */}
        <div className="flex flex-wrap gap-1.5">
          {STOCK_CATEGORY_TABS.map(tab => (
            <button
              key={tab.value}
              onClick={() => applyFilters({ stock_category: tab.value, opex_category: '', page: '1' })}
              className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                (filters.stock_category ?? 'all') === tab.value
                  ? 'bg-primary text-primary-foreground border-primary'
                  : 'border-input hover:bg-muted'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* OPEX category tabs */}
        <div className="flex flex-wrap gap-1.5">
          {OPEX_CATEGORY_TABS.map(tab => (
            <button
              key={tab.value}
              onClick={() => applyFilters({ opex_category: tab.value, stock_category: '', page: '1' })}
              className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                (filters.opex_category ?? 'all') === tab.value
                  ? 'bg-primary text-primary-foreground border-primary'
                  : 'border-input hover:bg-muted'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Stock status tabs */}
        <div className="flex flex-wrap gap-1.5">
          {STATUS_TABS.map(tab => {
            const count = tab.value === 'all' ? null
              : tab.value === 'MOVING' ? stats.by_stock_status.MOVING
              : tab.value === 'NON_MOVING' ? stats.by_stock_status.NON_MOVING
              : tab.value === 'DEAD' ? stats.by_stock_status.DEAD
              : stats.by_stock_status.OUT_OF_STOCK;
            const activeClass = stockStatus === tab.value
              ? tab.value === 'DEAD' ? 'bg-red-600 text-white border-red-600'
                : tab.value === 'NON_MOVING' ? 'bg-amber-500 text-white border-amber-500'
                : tab.value === 'OUT_OF_STOCK' ? 'bg-red-600 text-white border-red-600'
                : 'bg-primary text-primary-foreground border-primary'
              : 'border-input hover:bg-muted';
            return (
              <button
                key={tab.value}
                onClick={() => applyFilters({ stock_status: tab.value, page: '1' })}
                className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${activeClass}`}
              >
                {tab.label}
                {count !== null && <span className="ml-1.5 opacity-75">{count}</span>}
              </button>
            );
          })}
        </div>

        {/* Search + filters bar */}
        <Card>
          <CardContent className="flex flex-wrap items-center gap-3 p-4">
            <form onSubmit={(e) => { e.preventDefault(); applyFilters({ search, page: '1' }); }} className="flex min-w-64 flex-1 gap-2">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input className="pl-9" placeholder="Search SKU or material name..." value={search} onChange={e => setSearch(e.target.value)} />
              </div>
              <Button type="submit" variant="secondary">Search</Button>
            </form>

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

        {/* Table */}
        <Card>
          <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Material</TableHead>
                <TableHead>Category</TableHead>
                <TableHead>Movement</TableHead>
                <TableHead className="text-right">Available</TableHead>
                <TableHead className="text-right">Reorder</TableHead>
                <TableHead className="text-right">Unit Cost</TableHead>
                <TableHead className="text-right">Stock Value</TableHead>
                <TableHead>Warehouse</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-28"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {supplies.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={10} className="py-12 text-center text-muted-foreground">
                    <Boxes className="mx-auto mb-2 h-8 w-8 opacity-30" />
                    No materials match these filters.
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
                    <TableCell>
                      <SectionBadge section={supply.section} stockCategory={supply.stock_category} opexCategory={supply.opex_category} category={supply.category} />
                    </TableCell>
                    <TableCell>
                      <StockStatusBadge status={supply.stock_status} override={supply.stock_status_override} />
                    </TableCell>
                    <TableCell className={`text-right font-medium ${isLow ? 'text-orange-400' : ''}`}>
                      {available}
                      {isLow && <AlertTriangle className="ml-1 inline h-3.5 w-3.5" />}
                    </TableCell>
                    <TableCell className="text-right text-sm">{reorder}</TableCell>
                    <TableCell className="text-right text-sm">{formatCurrency(Number(supply.cost_price))}</TableCell>
                    <TableCell className="text-right text-sm font-medium">{formatCurrency(available * Number(supply.cost_price))}</TableCell>
                    <TableCell className="text-sm">{supply.stocks.map(stock => stock.warehouse?.name).filter(Boolean).join(', ') || '—'}</TableCell>
                    <TableCell>
                      <span className={`rounded-full px-2 py-0.5 text-xs ${supply.is_active ? 'bg-green-950/40 text-green-300' : 'bg-slate-800 text-slate-400'}`}>
                        {supply.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </TableCell>
                    <TableCell>
                      <div className="flex justify-end gap-1">
                        <Button size="icon" variant="ghost" onClick={() => setStockTarget(supply)}><SlidersHorizontal className="h-4 w-4" /></Button>
                        <Button size="icon" variant="ghost" title="Override stock status" onClick={() => setStatusTarget(supply)}><Tag className="h-4 w-4" /></Button>
                        <Button size="icon" variant="ghost" onClick={() => { setEditing(supply); setMaterialOpen(true); }}><Edit2 className="h-4 w-4" /></Button>
                        <Button size="icon" variant="ghost" className="text-red-500 hover:text-red-700" onClick={() => setDeleteTarget(supply)}><Trash2 className="h-4 w-4" /></Button>
                      </div>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
          </div>
          {supplies.last_page > 1 && (
            <div className="border-t p-3">
              <Paginator pagination={supplies} url="/inventory/supplies" params={filters as Record<string, string>} />
            </div>
          )}
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
                      <TableCell><span className={`rounded px-2 py-0.5 text-xs ${movement.quantity < 0 ? 'bg-red-950/40 text-red-300' : 'bg-green-950/40 text-green-300'}`}>{movement.type}</span></TableCell>
                      <TableCell className={`text-right font-medium ${movement.quantity < 0 ? 'text-red-400' : 'text-green-400'}`}>{movement.quantity > 0 ? `+${movement.quantity}` : movement.quantity}</TableCell>
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

      <MaterialDialog open={materialOpen} onClose={() => setMaterialOpen(false)} editing={editing} uoms={uoms} warehouses={warehouses} categories={stats.categories} onOverrideStatus={(s) => { setMaterialOpen(false); setStatusTarget(s); }} />
      <StockDialog supply={stockTarget} onClose={() => setStockTarget(null)} warehouses={warehouses} />
      <StatusOverrideDialog supply={statusTarget} onClose={() => setStatusTarget(null)} />
      <DeleteDialog supply={deleteTarget} onClose={() => setDeleteTarget(null)} />
    </AppLayout>
  );
}

function StatusOverrideDialog({ supply, onClose }: { supply: Supply | null; onClose: () => void }) {
  const form = useForm({
    stock_status: 'MOVING' as 'MOVING' | 'NON_MOVING' | 'DEAD',
    stock_status_override: true,
  });

  useEffect(() => {
    if (supply) {
      form.setData({
        stock_status: supply.stock_status,
        stock_status_override: supply.stock_status_override,
      });
      form.clearErrors();
    }
  }, [supply?.id]);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!supply) return;
    form.patch(`/inventory/supplies/${supply.id}/status`, {
      onSuccess: () => { toast.success('Status override applied.'); onClose(); },
      onError: () => toast.error('Failed to update status.'),
    });
  }

  function clearOverride() {
    if (!supply) return;
    form.setData({ stock_status: supply.stock_status, stock_status_override: false });
    form.patch(`/inventory/supplies/${supply.id}/status`, {
      data: { stock_status: supply.stock_status, stock_status_override: false },
      onSuccess: () => { toast.success('Status override cleared.'); onClose(); },
      onError: () => toast.error('Failed to clear status override.'),
    });
  }

  const STATUS_OPTIONS: { value: 'MOVING' | 'NON_MOVING' | 'DEAD'; label: string; cls: string }[] = [
    { value: 'MOVING',     label: 'Moving',     cls: 'border-green-500 bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-300' },
    { value: 'NON_MOVING', label: 'Non-Moving', cls: 'border-amber-500 bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' },
    { value: 'DEAD',       label: 'Dead Stock', cls: 'border-red-500 bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300' },
  ];

  return (
    <Dialog open={!!supply} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader><DialogTitle>Override Stock Status</DialogTitle></DialogHeader>
        {supply && (
          <form onSubmit={submit} className="space-y-4">
            <div className="rounded-md bg-muted p-3 text-sm">
              <div className="font-medium">{supply.name}</div>
              <div className="font-mono text-xs text-muted-foreground">{supply.sku}</div>
            </div>

            <div className="space-y-2">
              <Label>Status</Label>
              <div className="grid grid-cols-3 gap-2">
                {STATUS_OPTIONS.map(opt => (
                  <button
                    key={opt.value}
                    type="button"
                    onClick={() => form.setData('stock_status', opt.value)}
                    className={`rounded-lg border-2 px-3 py-2 text-sm font-medium transition-colors ${
                      form.data.stock_status === opt.value
                        ? opt.cls + ' border-2'
                        : 'border-input bg-background hover:bg-muted'
                    }`}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>
            </div>

            <label className="flex items-start gap-2 text-sm cursor-pointer">
              <input
                type="checkbox"
                className="mt-0.5"
                checked={form.data.stock_status_override}
                onChange={e => form.setData('stock_status_override', e.target.checked)}
              />
              <span>
                <span className="font-medium">Lock this status</span>
                <span className="block text-xs text-muted-foreground">Prevents auto-classification from overwriting this value. A ★ will appear on the badge.</span>
              </span>
            </label>

            <div className="flex items-center justify-between pt-1">
              {supply.stock_status_override && (
                <button type="button" onClick={clearOverride} className="text-xs text-muted-foreground underline hover:text-foreground">
                  Clear override (restore auto)
                </button>
              )}
              <div className="ml-auto flex gap-2">
                <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                <Button type="submit" disabled={form.processing}><Tag className="mr-2 h-4 w-4" />Apply</Button>
              </div>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}

function DeleteDialog({ supply, onClose }: { supply: Supply | null; onClose: () => void }) {
  const form = useForm({ delete_reason: '' });

  useEffect(() => {
    form.setData({ delete_reason: '' });
    form.clearErrors();
  }, [supply?.id]);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!supply) return;
    form.delete(`/inventory/supplies/${supply.id}`, {
      onSuccess: () => { toast.success('Material removed successfully.'); onClose(); },
      onError: () => toast.error('Failed to remove material. Please try again.'),
    });
  }

  return (
    <Dialog open={!!supply} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader><DialogTitle>Remove Material</DialogTitle></DialogHeader>
        {supply && (
          <form onSubmit={submit} className="space-y-3">
            <div className="rounded-md border border-red-800 bg-red-950/30 p-3 text-sm">
              <div className="font-medium text-red-300">{supply.name}</div>
              <div className="font-mono text-xs text-red-400">{supply.sku}</div>
            </div>
            <p className="text-sm text-muted-foreground">This will soft-delete the material. A reason is required for audit purposes.</p>
            <div className="space-y-1">
              <Label>Reason for deletion *</Label>
              <Textarea
                rows={2}
                placeholder="e.g. Wrong data entry, duplicate SKU..."
                value={form.data.delete_reason}
                onChange={e => form.setData('delete_reason', e.target.value)}
                required
              />
              {form.errors.delete_reason && <p className="text-xs text-red-600">{form.errors.delete_reason}</p>}
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
              <Button type="submit" variant="destructive" disabled={form.processing || !form.data.delete_reason.trim()}>
                <Trash2 className="mr-2 h-4 w-4" />Remove
              </Button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}

function SectionBadge({ section, stockCategory, opexCategory, category }: {
  section: 'STOCK' | 'OPEX' | string;
  stockCategory?: string;
  opexCategory?: string;
  category?: string;
}) {
  const STOCK_LABELS: Record<string, string> = {
    RAW_MATERIAL: 'Raw Materials',
    PRODUCTION_MATERIAL: 'Production',
    MERCHANDISE: 'Merchandise',
    RD_SUPPLY: 'R&D',
  };
  const OPEX_LABELS: Record<string, string> = {
    OFFICE_SUPPLY: 'Office Supplies',
    CLEANING_MATERIAL: 'Cleaning',
  };

  const label = opexCategory
    ? (OPEX_LABELS[opexCategory] ?? opexCategory)
    : stockCategory
      ? (STOCK_LABELS[stockCategory] ?? stockCategory)
      : (category ?? null);

  const dotColor = section === 'STOCK'
    ? 'bg-emerald-400'
    : section === 'OPEX'
      ? 'bg-blue-400'
      : 'bg-slate-400';

  return (
    <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
      <span className={`h-1.5 w-1.5 rounded-full ${dotColor}`} />
      {label ?? '—'}
    </span>
  );
}

function StockStatusBadge({ status, override }: { status: string; override: boolean }) {
  const cfg: Record<string, { cls: string; label: string }> = {
    MOVING:     { cls: 'bg-green-950/40 text-green-300',  label: 'Moving' },
    NON_MOVING: { cls: 'bg-amber-950/40 text-amber-300',  label: 'Non-Moving' },
    DEAD:       { cls: 'bg-red-950/40 text-red-300',      label: 'Dead Stock' },
  };
  const { cls, label } = cfg[status] ?? { cls: 'bg-slate-800 text-slate-400', label: status };
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>
      {label}{override && <span title="Manually set" className="opacity-60">★</span>}
    </span>
  );
}

function MaterialDialog({ open, onClose, editing, uoms, warehouses, categories, onOverrideStatus }: {
  open: boolean;
  onClose: () => void;
  editing: Supply | null;
  uoms: Uom[];
  warehouses: Warehouse[];
  categories: string[];
  onOverrideStatus?: (supply: Supply) => void;
}) {
  const form = useForm({
    sku: '',
    name: '',
    category: '',
    section: 'STOCK' as string,
    stock_category: '' as string,
    opex_category: '' as string,
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
      section: editing?.section ?? 'STOCK',
      stock_category: editing?.stock_category ?? '',
      opex_category: editing?.opex_category ?? '',
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
      form.put(`/inventory/supplies/${editing.id}`, {
        onSuccess: () => { toast.success('Material updated successfully.'); onClose(); },
        onError: () => toast.error('Failed to update material. Check the form for errors.'),
      });
    } else {
      form.post('/inventory/supplies', {
        onSuccess: () => { toast.success('Material created successfully.'); onClose(); form.reset(); },
        onError: () => toast.error('Failed to create material. Check the form for errors.'),
      });
    }
  }

  return (
    <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
      <DialogContent>
        <DialogHeader><DialogTitle>{editing ? 'Edit Material' : 'New Material'}</DialogTitle></DialogHeader>
        <form onSubmit={submit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label>SKU *</Label>
              <Input value={form.data.sku} onChange={e => form.setData('sku', e.target.value.toUpperCase())} required className="font-mono uppercase" />
              {form.errors.sku && <p className="text-xs text-red-600">{form.errors.sku}</p>}
            </div>
            <div className="space-y-1">
              <Label>Name *</Label>
              <Input value={form.data.name} onChange={e => form.setData('name', e.target.value)} required placeholder="Bottles, caps, labels..." />
              {form.errors.name && <p className="text-xs text-red-600">{form.errors.name}</p>}
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label>Section</Label>
              <Select value={form.data.section} onValueChange={v => { form.setData('section', v); form.setData('stock_category', ''); form.setData('opex_category', ''); }}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="STOCK">Section 1 — Stock</SelectItem>
                  <SelectItem value="OPEX">Section 2 — OPEX</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {form.data.section === 'STOCK' ? (
              <div className="space-y-1">
                <Label>Stock Category</Label>
                <Select value={form.data.stock_category || 'none'} onValueChange={v => form.setData('stock_category', v === 'none' ? '' : v)}>
                  <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">— None —</SelectItem>
                    <SelectItem value="RAW_MATERIAL">Raw Materials</SelectItem>
                    <SelectItem value="PRODUCTION_MATERIAL">Production Materials</SelectItem>
                    <SelectItem value="MERCHANDISE">Merchandise</SelectItem>
                    <SelectItem value="RD_SUPPLY">R&D Supplies</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            ) : (
              <div className="space-y-1">
                <Label>OPEX Category</Label>
                <Select value={form.data.opex_category || 'none'} onValueChange={v => form.setData('opex_category', v === 'none' ? '' : v)}>
                  <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">— None —</SelectItem>
                    <SelectItem value="OFFICE_SUPPLY">Office Supplies</SelectItem>
                    <SelectItem value="CLEANING_MATERIAL">Cleaning Materials</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label>Category (optional tag)</Label>
              <CategorySelect
                value={form.data.category}
                onChange={v => form.setData('category', v)}
                categories={categories}
              />
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
            <div className="space-y-1">
              <Label>Unit Cost *</Label>
              <Input type="number" min={0} step="0.0001" value={form.data.cost_price} onChange={e => form.setData('cost_price', Number(e.target.value))} required />
              {form.errors.cost_price && <p className="text-xs text-red-600">{form.errors.cost_price}</p>}
            </div>
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
          {editing && (
            <div className="rounded-md border bg-muted/40 p-3 space-y-1">
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Stock Status</span>
                {editing.stock_status_override && <span className="text-xs text-amber-400 font-medium">★ Manually locked</span>}
              </div>
              <div className="flex items-center justify-between">
                <StockStatusBadge status={editing.stock_status} override={editing.stock_status_override} />
                <button
                  type="button"
                  onClick={() => editing && onOverrideStatus?.(editing)}
                  className="text-xs text-blue-400 hover:underline"
                >
                  Override status →
                </button>
              </div>
              <p className="text-xs text-muted-foreground">Auto-classification runs on page load based on last activity date.</p>
            </div>
          )}
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

    form.post(`/inventory/supplies/${supply.id}/stock`, {
      onSuccess: () => { toast.success('Stock adjusted successfully.'); onClose(); },
      onError: () => toast.error('Failed to adjust stock. Please try again.'),
    });
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

const PRESET_CATEGORIES = [
  'Raw Materials',
  'Packaging',
  'Labels & Inserts',
  'Bottles & Caps',
  'Boxes & Cartons',
  'Pouches & Bags',
  'Office Supplies',
  'Cleaning Supplies',
  'Spare Parts',
];

function CategorySelect({ value, onChange, categories }: {
  value: string;
  onChange: (v: string) => void;
  categories: string[];
}) {
  const [custom, setCustom] = useState(false);

  // Merge presets with DB categories, deduplicated
  const allOptions = Array.from(
    new Set([...PRESET_CATEGORIES, ...categories])
  ).sort();

  // If editing an existing value not in the list, show custom input
  useEffect(() => {
    if (value && !allOptions.includes(value)) setCustom(true);
  }, []);

  if (custom) {
    return (
      <div className="flex gap-1.5">
        <Input
          value={value}
          onChange={e => onChange(e.target.value)}
          placeholder="Type category name..."
          className="flex-1"
          autoFocus
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="shrink-0 px-2 text-xs"
          onClick={() => { setCustom(false); onChange(''); }}
        >
          ✕
        </Button>
      </div>
    );
  }

  return (
    <Select
      value={value || '__none__'}
      onValueChange={v => {
        if (v === '__other__') { setCustom(true); onChange(''); }
        else if (v === '__none__') onChange('');
        else onChange(v);
      }}
    >
      <SelectTrigger><SelectValue placeholder="Select category..." /></SelectTrigger>
      <SelectContent>
        <SelectItem value="__none__"><span className="text-muted-foreground">— None —</span></SelectItem>
        {allOptions.map(c => <SelectItem key={c} value={c}>{c}</SelectItem>)}
        <SelectItem value="__other__"><span className="text-primary">+ Custom category...</span></SelectItem>
      </SelectContent>
    </Select>
  );
}

function StatCard({ label, value, tone }: { label: string; value: string | number; tone?: 'warn' }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className={`mt-1 text-2xl font-bold ${tone === 'warn' ? 'text-orange-400' : ''}`}>{value}</p>
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
