import { useEffect, useMemo, useState } from 'react';
import { useDebounce } from '@/hooks/use-debounce';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  AlertTriangle,
  Archive,
  Download,
  Edit2,
  PackagePlus,
  Plus,
  Search,
  SlidersHorizontal,
  Tag,
  Trash2,
} from 'lucide-react';
import Paginator from '@/components/Paginator';
import { DataTable } from '@/components/ui/data-table';
import { SupplyDetailDrawer } from '@/components/SupplyDetailDrawer';
import { ActivityTimeline } from '@/components/ActivityTimeline';
import { formatCurrency } from '@/lib/utils';
import type { ColumnDef } from '@tanstack/react-table';
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
    per_page?: number;
  };
  uoms: Uom[];
  warehouses: Warehouse[];
  recent_movements: SupplyMovement[];
}

const STOCK_CATEGORY_TABS = [
  { value: 'all', label: 'All' },
  { value: 'RAW_MATERIAL', label: 'Raw Materials' },
  { value: 'PRODUCTION_MATERIAL', label: 'Production' },
  { value: 'MERCHANDISE', label: 'Merchandise' },
  { value: 'RD_SUPPLY', label: 'R&D' },
];

const OPEX_CATEGORY_TABS = [
  { value: 'all', label: 'All' },
  { value: 'OFFICE_SUPPLY', label: 'Office Supplies' },
  { value: 'CLEANING_MATERIAL', label: 'Cleaning' },
];

const STATUS_TABS = [
  { value: 'all', label: 'All' },
  { value: 'MOVING', label: 'Moving' },
  { value: 'NON_MOVING', label: 'Non-Moving' },
  { value: 'DEAD', label: 'Dead Stock' },
  { value: 'OUT_OF_STOCK', label: 'Out of Stock' },
];

export default function SuppliesIndex({
  supplies,
  stats,
  filters,
  uoms,
  warehouses,
  recent_movements,
}: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [materialOpen, setMaterialOpen] = useState(false);
  const [editing, setEditing] = useState<Supply | null>(null);
  const [stockTarget, setStockTarget] = useState<Supply | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Supply | null>(null);
  const [statusTarget, setStatusTarget] = useState<Supply | null>(null);
  const [drawerSupplyId, setDrawerSupplyId] = useState<number | null>(null);

  const stockStatus = filters.stock_status ?? 'all';

  function applyFilters(overrides: Record<string, string>) {
    router.get(
      '/inventory/supplies',
      { ...filters, ...overrides },
      { preserveState: true, replace: true }
    );
  }

  const debouncedSearch = useDebounce(
    (val: string) => applyFilters({ search: val, page: '1' }),
    400
  );

  const stockValue = useMemo(
    () =>
      supplies.data.reduce(
        (sum, supply) => sum + totalStock(supply) * Number(supply.cost_price),
        0
      ),
    [supplies.data]
  );

  const columns = useMemo<ColumnDef<Supply>[]>(
    () => [
      {
        id: 'material',
        header: 'Material',
        enableHiding: false,
        cell: ({ row }) => {
          const s = row.original;
          return (
            <div className="min-w-[140px]">
              <div className="font-medium leading-tight">{s.name}</div>
              <div className="text-xs text-muted-foreground font-mono">
                {s.sku}
                {s.uom && ` / ${s.uom.abbreviation}`}
              </div>
            </div>
          );
        },
      },
      {
        id: 'category',
        header: 'Category',
        cell: ({ row }) => (
          <SectionBadge
            section={row.original.section}
            stockCategory={row.original.stock_category}
            opexCategory={row.original.opex_category}
            category={row.original.category}
          />
        ),
      },
      {
        id: 'movement',
        header: 'Movement',
        cell: ({ row }) => (
          <StockStatusBadge
            status={row.original.stock_status}
            override={row.original.stock_status_override}
          />
        ),
      },
      {
        id: 'available',
        header: () => <span className="block text-right">Available</span>,
        cell: ({ row }) => {
          const supply = row.original;
          const available = totalAvailable(supply);
          const reorder = supply.stocks[0]?.reorder_point ?? supply.reorder_point;
          const isLow = reorder > 0 && available <= reorder;
          return (
            <div className={`text-right font-medium ${isLow ? 'text-warning' : ''}`}>
              {available}
              {isLow && <AlertTriangle className="ml-1 inline h-3.5 w-3.5" />}
            </div>
          );
        },
      },
      {
        id: 'reorder',
        header: () => <span className="block text-right">Reorder</span>,
        cell: ({ row }) => (
          <div className="text-right text-sm">
            {row.original.stocks[0]?.reorder_point ?? row.original.reorder_point}
          </div>
        ),
      },
      {
        id: 'unit_cost',
        header: () => <span className="block text-right">Unit Cost</span>,
        cell: ({ row }) => (
          <div className="text-right text-sm">
            {formatCurrency(Number(row.original.cost_price))}
          </div>
        ),
      },
      {
        id: 'stock_value',
        header: () => <span className="block text-right">Stock Value</span>,
        cell: ({ row }) => {
          const available = totalAvailable(row.original);
          return (
            <div className="text-right text-sm font-medium">
              {formatCurrency(available * Number(row.original.cost_price))}
            </div>
          );
        },
      },
      {
        id: 'warehouse',
        header: 'Warehouse',
        cell: ({ row }) => (
          <div className="text-sm">
            {row.original.stocks
              .map((s) => s.warehouse?.name)
              .filter(Boolean)
              .join(', ') || '—'}
          </div>
        ),
      },
      {
        id: 'status',
        header: 'Status',
        cell: ({ row }) => {
          const s = row.original;
          return (
            <span
              className={`rounded-full px-2 py-0.5 text-xs ${s.is_active ? 'bg-success/10 text-success' : 'bg-muted text-muted-foreground'}`}
            >
              {s.is_active ? 'Active' : 'Inactive'}
            </span>
          );
        },
      },
      {
        id: 'actions',
        header: '',
        enableHiding: false,
        enableSorting: false,
        cell: ({ row }) => {
          const supply = row.original;
          return (
            <div className="flex justify-end gap-1">
              <Button
                size="icon"
                variant="ghost"
                title="View detail"
                onClick={() => setDrawerSupplyId(supply.id)}
              >
                <PackagePlus className="h-4 w-4" />
              </Button>
              <Button size="icon" variant="ghost" onClick={() => setStockTarget(supply)}>
                <SlidersHorizontal className="h-4 w-4" />
              </Button>
              <Button
                size="icon"
                variant="ghost"
                title="Override stock status"
                onClick={() => setStatusTarget(supply)}
              >
                <Tag className="h-4 w-4" />
              </Button>
              <Button
                size="icon"
                variant="ghost"
                onClick={() => {
                  setEditing(supply);
                  setMaterialOpen(true);
                }}
              >
                <Edit2 className="h-4 w-4" />
              </Button>
              <Button
                size="icon"
                variant="ghost"
                className="text-destructive hover:text-destructive/80"
                onClick={() => setDeleteTarget(supply)}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          );
        },
      },
    ],
    []
  );

  return (
    <AppLayout>
      <Head title="Materials" />
      <div className="space-y-4 p-4 sm:space-y-6 sm:p-6">
        {/* Header */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold">Materials</h1>
            <p className="text-sm text-muted-foreground">
              Stock, OPEX, and asset items across all warehouses.
            </p>
          </div>
          <div className="flex flex-wrap gap-2 mt-2 sm:mt-0">
            <Link href="/inventory/assets">
              <Button variant="outline" size="sm">
                CAPEX Assets
              </Button>
            </Link>
            {stats.trashed > 0 && (
              <Link href="/inventory/supplies/trash">
                <Button
                  variant="outline"
                  size="sm"
                  className="text-destructive border-destructive/20 hover:bg-destructive/5"
                >
                  <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                  Trash ({stats.trashed})
                </Button>
              </Link>
            )}
            <Button
              onClick={() => {
                setEditing(null);
                setMaterialOpen(true);
              }}
            >
              <Plus className="mr-2 h-4 w-4" />
              New Material
            </Button>
          </div>
        </div>

        {/* KPI cards */}
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          <StatCard label="Materials" value={stats.total} />
          <StatCard label="Active" value={stats.active} />
          <StatCard label="Low Stock" value={stats.low_stock} tone="warning" />
          <StatCard label="Page Stock Value" value={formatCurrency(stockValue)} />
        </div>

        {/* Stock category tabs */}
        <div className="flex flex-wrap gap-1.5">
          {STOCK_CATEGORY_TABS.map((tab) => (
            <button
              key={tab.value}
              onClick={() =>
                applyFilters({ stock_category: tab.value, opex_category: '', page: '1' })
              }
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
          {OPEX_CATEGORY_TABS.map((tab) => (
            <button
              key={tab.value}
              onClick={() =>
                applyFilters({ opex_category: tab.value, stock_category: '', page: '1' })
              }
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
          {STATUS_TABS.map((tab) => {
            const count =
              tab.value === 'all'
                ? null
                : tab.value === 'MOVING'
                  ? stats.by_stock_status.MOVING
                  : tab.value === 'NON_MOVING'
                    ? stats.by_stock_status.NON_MOVING
                    : tab.value === 'DEAD'
                      ? stats.by_stock_status.DEAD
                      : stats.by_stock_status.OUT_OF_STOCK;
            const activeClass =
              stockStatus === tab.value
                ? tab.value === 'DEAD'
                  ? 'bg-destructive text-destructive-foreground border-destructive'
                  : tab.value === 'NON_MOVING'
                    ? 'bg-warning text-warning-foreground border-warning'
                    : tab.value === 'OUT_OF_STOCK'
                      ? 'bg-destructive text-destructive-foreground border-destructive'
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
            <form
              onSubmit={(e) => {
                e.preventDefault();
                applyFilters({ search, page: '1' });
              }}
              className="flex w-full flex-1 gap-2 sm:min-w-64"
            >
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  className="pl-9"
                  placeholder="Search SKU or material name..."
                  value={search}
                  onChange={(e) => {
                    setSearch(e.target.value);
                    debouncedSearch(e.target.value);
                  }}
                />
              </div>
              <Button type="submit" variant="secondary">
                Search
              </Button>
            </form>

            <Select
              value={filters.status ?? 'all'}
              onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v, page: '1' })}
            >
              <SelectTrigger className="w-36">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>

            <Button
              variant="outline"
              size="sm"
              className="gap-1.5"
              onClick={() => {
                const params = new URLSearchParams();
                if (filters.search) params.set('search', filters.search);
                if (filters.status) params.set('status', filters.status);
                if (filters.stock_category && filters.stock_category !== 'all')
                  params.set('stock_category', filters.stock_category);
                if (filters.opex_category && filters.opex_category !== 'all')
                  params.set('opex_category', filters.opex_category);
                if (filters.stock_status && filters.stock_status !== 'all')
                  params.set('stock_status', filters.stock_status);
                window.location.href = `/inventory/supplies/export?${params.toString()}`;
              }}
            >
              <Download className="h-3.5 w-3.5" />
              Export CSV
            </Button>
          </CardContent>
        </Card>

        {/* Table */}
        <Card className="p-4 space-y-3">
          {/* Per-page + pagination row */}
          <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <span>Show</span>
              <Select
                value={String(filters.per_page ?? 25)}
                onValueChange={(v) => applyFilters({ per_page: v, page: '1' })}
              >
                <SelectTrigger className="h-8 w-20">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {[10, 25, 50, 100].map((n) => (
                    <SelectItem key={n} value={String(n)}>
                      {n}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <span>per page</span>
            </div>
            <span className="text-xs text-muted-foreground">
              {supplies.from}–{supplies.to} of {supplies.total} materials
            </span>
          </div>

          <DataTable
            columns={columns}
            data={supplies.data}
            storageKey="supplies_cols_v1"
            emptyMessage="No materials match these filters."
            toolbar={(table) => {
              const selected = table.getFilteredSelectedRowModel().rows;
              if (selected.length === 0) return null;
              const selectedSupplies = selected.map((r) => r.original);
              return (
                <div className="flex items-center gap-2">
                  <span className="text-xs font-medium text-muted-foreground">
                    {selected.length} selected
                  </span>
                  <Button
                    size="sm"
                    variant="outline"
                    className="h-7 gap-1 text-xs"
                    onClick={() => {
                      const rows = selectedSupplies.map((s) => ({
                        SKU: s.sku,
                        Name: s.name,
                        Category: s.category ?? '',
                        Available: totalAvailable(s),
                        'Unit Cost': Number(s.cost_price),
                        'Stock Value': totalAvailable(s) * Number(s.cost_price),
                        Status: s.is_active ? 'Active' : 'Inactive',
                        'Stock Status': s.stock_status,
                      }));
                      import('xlsx').then(({ utils, writeFile }) => {
                        const ws = utils.json_to_sheet(rows);
                        const wb = utils.book_new();
                        utils.book_append_sheet(wb, ws, 'Materials');
                        writeFile(wb, `materials-export-${Date.now()}.xlsx`);
                      });
                    }}
                  >
                    <Download className="h-3 w-3" />
                    Export
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    className="h-7 gap-1 text-xs text-destructive hover:text-destructive/80"
                    onClick={() => {
                      if (!confirm(`Archive ${selected.length} selected material(s)?`)) return;
                      selectedSupplies.forEach((s) => {
                        router.patch(
                          `/inventory/supplies/${s.id}`,
                          { is_active: false },
                          { preserveState: true }
                        );
                      });
                      table.resetRowSelection();
                    }}
                  >
                    <Archive className="h-3 w-3" />
                    Archive
                  </Button>
                </div>
              );
            }}
          />

          {supplies.last_page > 1 && (
            <div className="border-t pt-3">
              <Paginator
                pagination={supplies}
                url="/inventory/supplies"
                params={filters as Record<string, string>}
              />
            </div>
          )}
        </Card>

        {recent_movements.length > 0 && (
          <Card>
            <CardContent className="p-0">
              <div className="border-b px-4 py-3 text-sm font-medium">
                Recent Material Movements
              </div>
              <div className="p-5">
                <ActivityTimeline
                  showSupply
                  events={recent_movements.map((m) => ({
                    id: m.id,
                    type: m.type,
                    quantity: m.quantity,
                    notes: m.notes,
                    created_at: m.created_at,
                    warehouse_name: m.warehouse?.name,
                    supply_name: m.supply?.name,
                    supply_sku: m.supply?.sku,
                  }))}
                />
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      <SupplyDetailDrawer
        supplyId={drawerSupplyId}
        onClose={() => setDrawerSupplyId(null)}
        onEdit={(id) => {
          const s = supplies.data.find((x) => x.id === id) ?? null;
          setEditing(s);
          setMaterialOpen(true);
          setDrawerSupplyId(null);
        }}
        onAdjustStock={(id) => {
          const s = supplies.data.find((x) => x.id === id) ?? null;
          setStockTarget(s);
          setDrawerSupplyId(null);
        }}
        onOverrideStatus={(id) => {
          const s = supplies.data.find((x) => x.id === id) ?? null;
          setStatusTarget(s);
          setDrawerSupplyId(null);
        }}
      />
      <MaterialDialog
        open={materialOpen}
        onClose={() => setMaterialOpen(false)}
        editing={editing}
        uoms={uoms}
        warehouses={warehouses}
        categories={stats.categories}
        onOverrideStatus={(s) => {
          setMaterialOpen(false);
          setStatusTarget(s);
        }}
      />
      <StockDialog
        supply={stockTarget}
        onClose={() => setStockTarget(null)}
        warehouses={warehouses}
      />
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
      onSuccess: () => {
        toast.success('Status override applied.');
        onClose();
      },
      onError: () => toast.error('Failed to update status.'),
    });
  }

  function clearOverride() {
    if (!supply) return;
    form.setData({ stock_status: supply.stock_status, stock_status_override: false });
    form.patch(`/inventory/supplies/${supply.id}/status`, {
      data: { stock_status: supply.stock_status, stock_status_override: false },
      onSuccess: () => {
        toast.success('Status override cleared.');
        onClose();
      },
      onError: () => toast.error('Failed to clear status override.'),
    });
  }

  const STATUS_OPTIONS: { value: 'MOVING' | 'NON_MOVING' | 'DEAD'; label: string; cls: string }[] =
    [
      { value: 'MOVING', label: 'Moving', cls: 'border-success bg-success/10 text-success' },
      {
        value: 'NON_MOVING',
        label: 'Non-Moving',
        cls: 'border-warning bg-warning/10 text-warning',
      },
      {
        value: 'DEAD',
        label: 'Dead Stock',
        cls: 'border-destructive bg-destructive/10 text-destructive',
      },
    ];

  return (
    <Dialog open={!!supply} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Override Stock Status</DialogTitle>
        </DialogHeader>
        {supply && (
          <form onSubmit={submit} className="space-y-4">
            <div className="rounded-md bg-muted p-3 text-sm">
              <div className="font-medium">{supply.name}</div>
              <div className="font-mono text-xs text-muted-foreground">{supply.sku}</div>
            </div>

            <div className="space-y-2">
              <Label>Status</Label>
              <div className="grid grid-cols-3 gap-2">
                {STATUS_OPTIONS.map((opt) => (
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
                onChange={(e) => form.setData('stock_status_override', e.target.checked)}
              />
              <span>
                <span className="font-medium">Lock this status</span>
                <span className="block text-xs text-muted-foreground">
                  Prevents auto-classification from overwriting this value. A ★ will appear on the
                  badge.
                </span>
              </span>
            </label>

            <div className="flex items-center justify-between pt-1">
              {supply.stock_status_override && (
                <button
                  type="button"
                  onClick={clearOverride}
                  className="text-xs text-muted-foreground underline hover:text-foreground"
                >
                  Clear override (restore auto)
                </button>
              )}
              <div className="ml-auto flex gap-2">
                <Button type="button" variant="outline" onClick={onClose}>
                  Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                  <Tag className="mr-2 h-4 w-4" />
                  Apply
                </Button>
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
      onSuccess: () => {
        toast.success('Material removed successfully.');
        onClose();
      },
      onError: () => toast.error('Failed to remove material. Please try again.'),
    });
  }

  return (
    <Dialog open={!!supply} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Remove Material</DialogTitle>
        </DialogHeader>
        {supply && (
          <form onSubmit={submit} className="space-y-3">
            <div className="rounded-md border border-destructive/20 bg-destructive/5 p-3 text-sm">
              <div className="font-medium text-destructive">{supply.name}</div>
              <div className="font-mono text-xs text-destructive/70">{supply.sku}</div>
            </div>
            <p className="text-sm text-muted-foreground">
              This will soft-delete the material. A reason is required for audit purposes.
            </p>
            <div className="space-y-1">
              <Label>Reason for deletion *</Label>
              <Textarea
                rows={2}
                placeholder="e.g. Wrong data entry, duplicate SKU..."
                value={form.data.delete_reason}
                onChange={(e) => form.setData('delete_reason', e.target.value)}
                required
              />
              {form.errors.delete_reason && (
                <p className="text-xs text-destructive">{form.errors.delete_reason}</p>
              )}
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={onClose}>
                Cancel
              </Button>
              <Button
                type="submit"
                variant="destructive"
                disabled={form.processing || !form.data.delete_reason.trim()}
              >
                <Trash2 className="mr-2 h-4 w-4" />
                Remove
              </Button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}

function SectionBadge({
  section,
  stockCategory,
  opexCategory,
  category,
}: {
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

  const dotColor =
    section === 'STOCK' ? 'bg-success' : section === 'OPEX' ? 'bg-info' : 'bg-muted-foreground';

  return (
    <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
      <span className={`h-1.5 w-1.5 rounded-full ${dotColor}`} />
      {label ?? '—'}
    </span>
  );
}

function StockStatusBadge({ status, override }: { status: string; override: boolean }) {
  const cfg: Record<string, { cls: string; label: string }> = {
    MOVING: { cls: 'bg-success/10 text-success', label: 'Moving' },
    NON_MOVING: { cls: 'bg-warning/10 text-warning', label: 'Non-Moving' },
    DEAD: { cls: 'bg-destructive/10 text-destructive', label: 'Dead Stock' },
  };
  const { cls, label } = cfg[status] ?? { cls: 'bg-muted text-muted-foreground', label: status };
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}
    >
      {label}
      {override && (
        <span title="Manually set" className="opacity-60">
          ★
        </span>
      )}
    </span>
  );
}

function MaterialDialog({
  open,
  onClose,
  editing,
  uoms,
  warehouses,
  categories,
  onOverrideStatus,
}: {
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
        onSuccess: () => {
          toast.success('Material updated successfully.');
          onClose();
        },
        onError: () => toast.error('Failed to update material. Check the form for errors.'),
      });
    } else {
      form.post('/inventory/supplies', {
        onSuccess: () => {
          toast.success('Material created successfully.');
          onClose();
          form.reset();
        },
        onError: () => toast.error('Failed to create material. Check the form for errors.'),
      });
    }
  }

  return (
    <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{editing ? 'Edit Material' : 'New Material'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label>SKU *</Label>
              <Input
                value={form.data.sku}
                onChange={(e) => form.setData('sku', e.target.value.toUpperCase())}
                required
                className="font-mono uppercase"
              />
              {form.errors.sku && <p className="text-xs text-red-600">{form.errors.sku}</p>}
            </div>
            <div className="space-y-1">
              <Label>Name *</Label>
              <Input
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                required
                placeholder="Bottles, caps, labels..."
              />
              {form.errors.name && <p className="text-xs text-red-600">{form.errors.name}</p>}
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label>Section</Label>
              <Select
                value={form.data.section}
                onValueChange={(v) => {
                  form.setData('section', v);
                  form.setData('stock_category', '');
                  form.setData('opex_category', '');
                }}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="STOCK">Section 1 — Stock</SelectItem>
                  <SelectItem value="OPEX">Section 2 — OPEX</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {form.data.section === 'STOCK' ? (
              <div className="space-y-1">
                <Label>Stock Category</Label>
                <Select
                  value={form.data.stock_category || 'none'}
                  onValueChange={(v) => form.setData('stock_category', v === 'none' ? '' : v)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select..." />
                  </SelectTrigger>
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
                <Select
                  value={form.data.opex_category || 'none'}
                  onValueChange={(v) => form.setData('opex_category', v === 'none' ? '' : v)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select..." />
                  </SelectTrigger>
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
                onChange={(v) => form.setData('category', v)}
                categories={categories}
              />
            </div>
            <div className="space-y-1">
              <Label>UoM</Label>
              <Select
                value={form.data.uom_id || 'none'}
                onValueChange={(value) => form.setData('uom_id', value === 'none' ? '' : value)}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None</SelectItem>
                  {uoms.map((uom) => (
                    <SelectItem key={uom.id} value={String(uom.id)}>
                      {uom.name} ({uom.abbreviation})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div className="space-y-1">
              <Label>Unit Cost *</Label>
              <Input
                type="number"
                min={0}
                step="0.0001"
                value={form.data.cost_price}
                onChange={(e) => form.setData('cost_price', Number(e.target.value))}
                required
              />
              {form.errors.cost_price && (
                <p className="text-xs text-red-600">{form.errors.cost_price}</p>
              )}
            </div>
            <div className="space-y-1">
              <Label>Min Stock</Label>
              <Input
                type="number"
                min={0}
                value={form.data.min_stock_level}
                onChange={(e) => form.setData('min_stock_level', Number(e.target.value))}
              />
            </div>
            <div className="space-y-1">
              <Label>Reorder Point</Label>
              <Input
                type="number"
                min={0}
                value={form.data.reorder_point}
                onChange={(e) => form.setData('reorder_point', Number(e.target.value))}
              />
            </div>
          </div>
          {!editing && (
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label>Initial Stock</Label>
                <Input
                  type="number"
                  min={0}
                  value={form.data.initial_stock}
                  onChange={(e) => form.setData('initial_stock', Number(e.target.value))}
                />
              </div>
              <div className="space-y-1">
                <Label>Warehouse</Label>
                <Select
                  value={form.data.warehouse_id || 'default'}
                  onValueChange={(value) =>
                    form.setData('warehouse_id', value === 'default' ? '' : value)
                  }
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="default">Default warehouse</SelectItem>
                    {warehouses.map((warehouse) => (
                      <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                        {warehouse.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          )}
          <div className="space-y-1">
            <Label>Description</Label>
            <Textarea
              rows={2}
              value={form.data.description}
              onChange={(e) => form.setData('description', e.target.value)}
            />
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.data.is_active}
              onChange={(e) => form.setData('is_active', e.target.checked)}
            />
            Active
          </label>
          {editing && (
            <div className="rounded-md border bg-muted/40 p-3 space-y-1">
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                  Stock Status
                </span>
                {editing.stock_status_override && (
                  <span className="text-xs text-warning font-medium">★ Manually locked</span>
                )}
              </div>
              <div className="flex items-center justify-between">
                <StockStatusBadge
                  status={editing.stock_status}
                  override={editing.stock_status_override}
                />
                <button
                  type="button"
                  onClick={() => editing && onOverrideStatus?.(editing)}
                  className="text-xs text-primary hover:underline"
                >
                  Override status →
                </button>
              </div>
              <p className="text-xs text-muted-foreground">
                Auto-classification runs on page load based on last activity date.
              </p>
            </div>
          )}
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={form.processing}>
              {editing ? 'Save' : 'Create'}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function StockDialog({
  supply,
  onClose,
  warehouses,
}: {
  supply: Supply | null;
  onClose: () => void;
  warehouses: Warehouse[];
}) {
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
      onSuccess: () => {
        toast.success('Stock adjusted successfully.');
        onClose();
      },
      onError: () => toast.error('Failed to adjust stock. Please try again.'),
    });
  }

  return (
    <Dialog open={!!supply} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Adjust Material Stock</DialogTitle>
        </DialogHeader>
        {supply && (
          <form onSubmit={submit} className="space-y-3">
            <div className="rounded-md bg-muted p-3 text-sm">
              <div className="font-medium">{supply.name}</div>
              <div className="font-mono text-xs text-muted-foreground">{supply.sku}</div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label>Action</Label>
                <Select
                  value={form.data.type}
                  onValueChange={(value) => form.setData('type', value)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="stock_in">Stock In</SelectItem>
                    <SelectItem value="stock_out">Stock Out</SelectItem>
                    <SelectItem value="adjustment">Set Exact Qty</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1">
                <Label>{form.data.type === 'adjustment' ? 'New Qty' : 'Quantity'}</Label>
                <Input
                  type="number"
                  min={1}
                  value={form.data.quantity}
                  onChange={(e) => form.setData('quantity', Number(e.target.value))}
                />
              </div>
            </div>
            <div className="space-y-1">
              <Label>Warehouse</Label>
              <Select
                value={form.data.warehouse_id || 'default'}
                onValueChange={(value) =>
                  form.setData('warehouse_id', value === 'default' ? '' : value)
                }
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="default">Default warehouse</SelectItem>
                  {warehouses.map((warehouse) => (
                    <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                      {warehouse.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label>Notes</Label>
              <Input
                value={form.data.notes}
                onChange={(e) => form.setData('notes', e.target.value)}
              />
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={onClose}>
                Cancel
              </Button>
              <Button type="submit" disabled={form.processing}>
                <PackagePlus className="mr-2 h-4 w-4" />
                Post
              </Button>
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

function CategorySelect({
  value,
  onChange,
  categories,
}: {
  value: string;
  onChange: (v: string) => void;
  categories: string[];
}) {
  const [custom, setCustom] = useState(false);

  // Merge presets with DB categories, deduplicated
  const allOptions = Array.from(new Set([...PRESET_CATEGORIES, ...categories])).sort();

  // If editing an existing value not in the list, show custom input
  useEffect(() => {
    if (value && !allOptions.includes(value)) setCustom(true);
  }, []);

  if (custom) {
    return (
      <div className="flex gap-1.5">
        <Input
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder="Type category name..."
          className="flex-1"
          autoFocus
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="shrink-0 px-2 text-xs"
          onClick={() => {
            setCustom(false);
            onChange('');
          }}
        >
          ✕
        </Button>
      </div>
    );
  }

  return (
    <Select
      value={value || '__none__'}
      onValueChange={(v) => {
        if (v === '__other__') {
          setCustom(true);
          onChange('');
        } else if (v === '__none__') onChange('');
        else onChange(v);
      }}
    >
      <SelectTrigger>
        <SelectValue placeholder="Select category..." />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="__none__">
          <span className="text-muted-foreground">— None —</span>
        </SelectItem>
        {allOptions.map((c) => (
          <SelectItem key={c} value={c}>
            {c}
          </SelectItem>
        ))}
        <SelectItem value="__other__">
          <span className="text-primary">+ Custom category...</span>
        </SelectItem>
      </SelectContent>
    </Select>
  );
}

function StatCard({
  label,
  value,
  tone,
}: {
  label: string;
  value: string | number;
  tone?: 'warning';
}) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className={`mt-1 text-2xl font-bold ${tone === 'warning' ? 'text-warning' : ''}`}>
          {value}
        </p>
      </CardContent>
    </Card>
  );
}

function totalStock(supply: Supply): number {
  return supply.stocks.reduce((sum, stock) => sum + Number(stock.current_stock), 0);
}

function totalAvailable(supply: Supply): number {
  return supply.stocks.reduce(
    (sum, stock) =>
      sum + Number(stock.available_stock ?? stock.current_stock - stock.reserved_stock),
    0
  );
}
