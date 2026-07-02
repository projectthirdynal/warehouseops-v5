import { useState, useMemo } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { ArrowLeft, Plus, Skull, AlertTriangle, Search, Tag } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import Paginator from '@/components/Paginator';
import type { PaginatedResponse } from '@/types';

interface StockRow {
  warehouse_id: number | null;
  warehouse_name: string | null;
  available: number;
}

interface ItemOption {
  id: number;
  sku: string;
  name: string;
  category: string | null;
  cost_price: number;
  stocks: StockRow[];
}

interface SupplyOption extends ItemOption {
  section: string;
}

interface Warehouse {
  id: number;
  name: string;
  code: string;
}

interface DeadEntry {
  id: number;
  item_type: 'supply' | 'product';
  supply?: { id: number; sku: string; name: string } | null;
  product?: { id: number; sku: string; name: string } | null;
  warehouse?: { id: number; name: string; code: string } | null;
  quantity: number;
  unit_cost: string;
  total_value: string;
  reason: string | null;
  recorder?: { name: string } | null;
  created_at: string;
}

interface DeadSupply {
  id: number;
  sku: string;
  name: string;
  section: string;
  category: string | null;
  cost_price: number;
  uom: string | null;
  stock_status_override: boolean;
  total_stock: number;
  total_value: number;
  warehouses: { name: string; available: number }[];
}

interface Props {
  entries: PaginatedResponse<DeadEntry>;
  total_dead_value: number;
  dead_supplies: DeadSupply[];
  supplies: SupplyOption[];
  products: ItemOption[];
  warehouses: Warehouse[];
  filters: {
    item_type?: string;
    warehouse_id?: string;
    from?: string;
    to?: string;
  };
}

export default function DeadStockIndex({
  entries,
  total_dead_value,
  dead_supplies,
  supplies,
  products,
  warehouses,
  filters,
}: Props) {
  const [open, setOpen] = useState(false);
  const [itemType, setItemType] = useState<'supply' | 'product'>('supply');
  const [selectedId, setSelectedId] = useState('');
  const [search, setSearch] = useState('');

  const form = useForm({
    item_type: 'supply' as 'supply' | 'product',
    supply_id: '',
    product_id: '',
    warehouse_id: '',
    quantity: '',
    unit_cost: '',
    reason: '',
  });

  function applyFilters(overrides: Record<string, string>) {
    router.get(
      '/inventory/dead-stock',
      { ...filters, ...overrides },
      { preserveState: true, replace: true }
    );
  }

  function openDialog() {
    form.reset();
    setItemType('supply');
    setSelectedId('');
    setSearch('');
    setOpen(true);
  }

  function handleTypeChange(t: 'supply' | 'product') {
    setItemType(t);
    setSelectedId('');
    form.setData({
      ...form.data,
      item_type: t,
      supply_id: '',
      product_id: '',
      warehouse_id: '',
      unit_cost: '',
    });
  }

  function handleItemSelect(id: string) {
    setSelectedId(id);
    const numId = parseInt(id, 10);
    const item =
      itemType === 'supply'
        ? supplies.find((s) => s.id === numId)
        : products.find((p) => p.id === numId);
    form.setData({
      ...form.data,
      supply_id: itemType === 'supply' ? id : '',
      product_id: itemType === 'product' ? id : '',
      warehouse_id: '',
      unit_cost: item ? String(item.cost_price) : '',
    });
  }

  const selectedItem = useMemo(() => {
    if (!selectedId) return null;
    const numId = parseInt(selectedId, 10);
    return (
      (itemType === 'supply'
        ? supplies.find((s) => s.id === numId)
        : products.find((p) => p.id === numId)) ?? null
    );
  }, [selectedId, itemType, supplies, products]);

  const filteredOptions = useMemo((): ItemOption[] => {
    const list: ItemOption[] = itemType === 'supply' ? supplies : products;
    if (!search) return list;
    const q = search.toLowerCase();
    return list.filter((o) => o.name.toLowerCase().includes(q) || o.sku.toLowerCase().includes(q));
  }, [search, itemType, supplies, products]);

  const availableInWarehouse = useMemo(() => {
    if (!selectedItem || !form.data.warehouse_id) return null;
    const wId = parseInt(form.data.warehouse_id, 10);
    return selectedItem.stocks.find((s) => s.warehouse_id === wId)?.available ?? null;
  }, [selectedItem, form.data.warehouse_id]);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    form.post('/inventory/dead-stock', {
      onSuccess: () => {
        setOpen(false);
        form.reset();
        setSelectedId('');
        setSearch('');
      },
      preserveScroll: true,
    });
  }

  return (
    <AppLayout>
      <Head title="Dead Stock" />
      <div className="space-y-4 p-4 sm:space-y-4 sm:p-6">
        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1 flex items-center gap-2">
              <Link
                href="/inventory"
                className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
              >
                <ArrowLeft className="h-3 w-3" /> Dashboard
              </Link>
            </div>
            <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
              <Skull className="h-6 w-6 text-destructive" />
              Dead Stock
            </h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Manually declare dead items. Stock is written off immediately on record.
            </p>
          </div>
          <div className="flex items-center gap-3">
            <div className="rounded-lg border border-destructive/20 bg-destructive/5 px-5 py-3 text-right">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-destructive">
                Total Dead Value
              </p>
              <p className="mt-0.5 text-2xl font-bold tabular-nums text-destructive">
                {formatCurrency(total_dead_value)}
              </p>
            </div>
            <Button onClick={openDialog} className="gap-1.5">
              <Plus className="h-4 w-4" /> Record Dead Stock
            </Button>
          </div>
        </div>

        {/* Classified Dead Stock — materials tagged DEAD via status badge */}
        {dead_supplies.length > 0 && (
          <Card>
            <CardContent className="p-0">
              <div className="flex items-center justify-between border-b px-4 py-3">
                <div>
                  <p className="text-sm font-semibold flex items-center gap-2">
                    <Tag className="h-4 w-4 text-destructive" />
                    Classified as Dead Stock
                    <span className="ml-1 rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-bold text-destructive">
                      {dead_supplies.length}
                    </span>
                  </p>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    Materials tagged DEAD via the override or auto-classification. Use the Tag
                    button in Materials to reclassify.
                  </p>
                </div>
                <Link href="/inventory/supplies?stock_status=DEAD">
                  <Button variant="outline" size="sm">
                    Manage in Materials
                  </Button>
                </Link>
              </div>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>SKU</TableHead>
                    <TableHead>Material</TableHead>
                    <TableHead>Category</TableHead>
                    <TableHead>Warehouses</TableHead>
                    <TableHead className="text-right">Total Stock</TableHead>
                    <TableHead className="text-right">Est. Value</TableHead>
                    <TableHead>Source</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {dead_supplies.map((s) => (
                    <TableRow key={s.id}>
                      <TableCell className="font-mono text-xs text-muted-foreground">
                        {s.sku}
                      </TableCell>
                      <TableCell className="font-medium text-sm">{s.name}</TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {s.category ?? '—'}
                      </TableCell>
                      <TableCell className="text-sm">
                        {s.warehouses.length > 0
                          ? s.warehouses.map((w) => `${w.name} (${w.available})`).join(', ')
                          : '—'}
                      </TableCell>
                      <TableCell className="text-right tabular-nums font-medium">
                        {s.total_stock.toLocaleString()}
                        {s.uom ? ` ${s.uom}` : ''}
                      </TableCell>
                      <TableCell className="text-right tabular-nums font-semibold text-destructive">
                        {formatCurrency(s.total_value)}
                      </TableCell>
                      <TableCell>
                        {s.stock_status_override ? (
                          <span className="inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-xs text-warning">
                            <Tag className="h-3 w-3" />
                            Manual
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                            Auto
                          </span>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Write-off Ledger Filters */}
        <Card>
          <CardContent className="flex flex-wrap items-end gap-3 p-4">
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Type</label>
              <Select
                value={filters.item_type ?? 'all'}
                onValueChange={(v) => applyFilters({ item_type: v === 'all' ? '' : v, page: '1' })}
              >
                <SelectTrigger className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Types</SelectItem>
                  <SelectItem value="supply">Materials</SelectItem>
                  <SelectItem value="product">Products</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Warehouse</label>
              <Select
                value={filters.warehouse_id ?? 'all'}
                onValueChange={(v) =>
                  applyFilters({ warehouse_id: v === 'all' ? '' : v, page: '1' })
                }
              >
                <SelectTrigger className="w-44">
                  <SelectValue placeholder="All Warehouses" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Warehouses</SelectItem>
                  {warehouses.map((w) => (
                    <SelectItem key={w.id} value={String(w.id)}>
                      {w.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">From</label>
              <Input
                type="date"
                className="w-36"
                value={filters.from ?? ''}
                onChange={(e) => applyFilters({ from: e.target.value, page: '1' })}
              />
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">To</label>
              <Input
                type="date"
                className="w-36"
                value={filters.to ?? ''}
                onChange={(e) => applyFilters({ to: e.target.value, page: '1' })}
              />
            </div>
            {(filters.item_type || filters.warehouse_id || filters.from || filters.to) && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => applyFilters({ item_type: '', warehouse_id: '', from: '', to: '' })}
              >
                Clear
              </Button>
            )}
          </CardContent>
        </Card>

        {/* Write-off Ledger Table */}
        <Card>
          <div className="border-b px-4 py-3">
            <p className="text-sm font-semibold flex items-center gap-2">
              <Skull className="h-4 w-4 text-destructive" />
              Write-off Ledger
            </p>
            <p className="text-xs text-muted-foreground mt-0.5">
              Stock physically written off and deducted from inventory. Use "Record Dead Stock" to
              add entries.
            </p>
          </div>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Type</TableHead>
                <TableHead>SKU</TableHead>
                <TableHead>Item</TableHead>
                <TableHead>Warehouse</TableHead>
                <TableHead className="text-right">Qty</TableHead>
                <TableHead className="text-right">Unit Cost</TableHead>
                <TableHead className="text-right">Dead Value</TableHead>
                <TableHead>Reason</TableHead>
                <TableHead>Recorded By</TableHead>
                <TableHead>Date</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {entries.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={10} className="py-12 text-center text-muted-foreground">
                    <Skull className="mx-auto mb-2 h-8 w-8 opacity-20" />
                    No dead stock records found.
                  </TableCell>
                </TableRow>
              ) : (
                entries.data.map((entry) => {
                  const item = entry.supply ?? entry.product;
                  return (
                    <TableRow key={entry.id}>
                      <TableCell>
                        <span
                          className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                            entry.item_type === 'supply'
                              ? 'bg-warning/10 text-warning'
                              : 'bg-info/10 text-info'
                          }`}
                        >
                          {entry.item_type === 'supply' ? 'Material' : 'Product'}
                        </span>
                      </TableCell>
                      <TableCell className="font-mono text-xs text-muted-foreground">
                        {item?.sku ?? '—'}
                      </TableCell>
                      <TableCell className="max-w-[180px] truncate font-medium text-sm">
                        {item?.name ?? '—'}
                      </TableCell>
                      <TableCell className="text-sm">{entry.warehouse?.name ?? '—'}</TableCell>
                      <TableCell className="text-right tabular-nums font-medium">
                        {Number(entry.quantity).toLocaleString()}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-sm">
                        {formatCurrency(Number(entry.unit_cost))}
                      </TableCell>
                      <TableCell className="text-right tabular-nums font-semibold text-destructive">
                        {formatCurrency(Number(entry.total_value))}
                      </TableCell>
                      <TableCell className="max-w-[160px] truncate text-xs text-muted-foreground">
                        {entry.reason ?? '—'}
                      </TableCell>
                      <TableCell className="text-sm">{entry.recorder?.name ?? '—'}</TableCell>
                      <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                        {formatDate(entry.created_at)}
                      </TableCell>
                    </TableRow>
                  );
                })
              )}
            </TableBody>
          </Table>
          {entries.last_page > 1 && (
            <div className="border-t p-3">
              <Paginator
                pagination={entries}
                url="/inventory/dead-stock"
                params={filters as Record<string, string>}
              />
            </div>
          )}
        </Card>
      </div>

      {/* Record Dialog */}
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Skull className="h-5 w-5 text-destructive" /> Record Dead Stock
            </DialogTitle>
          </DialogHeader>

          <form onSubmit={submit} className="space-y-4">
            {/* Item type toggle */}
            <div className="flex rounded-md border p-1 gap-1">
              {(['supply', 'product'] as const).map((t) => (
                <button
                  key={t}
                  type="button"
                  onClick={() => handleTypeChange(t)}
                  className={`flex-1 rounded py-1.5 text-sm font-medium transition-colors ${
                    itemType === t
                      ? 'bg-foreground text-background'
                      : 'text-muted-foreground hover:text-foreground'
                  }`}
                >
                  {t === 'supply' ? 'Material' : 'Product'}
                </button>
              ))}
            </div>

            {/* Search + select item */}
            <div className="space-y-1.5">
              <label className="text-sm font-medium">
                {itemType === 'supply' ? 'Material' : 'Product'}{' '}
                <span className="text-destructive">*</span>
              </label>
              <div className="relative">
                <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                <Input
                  placeholder={`Search ${itemType === 'supply' ? 'materials' : 'products'}…`}
                  className="pl-8"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
              </div>
              <div className="max-h-48 overflow-y-auto rounded-md border">
                {filteredOptions.length === 0 ? (
                  <p className="p-3 text-center text-sm text-muted-foreground">No results</p>
                ) : (
                  filteredOptions.map((o) => (
                    <button
                      key={o.id}
                      type="button"
                      onClick={() => handleItemSelect(String(o.id))}
                      className={`w-full px-3 py-2 text-left text-sm transition-colors hover:bg-accent ${
                        selectedId === String(o.id) ? 'bg-accent font-medium' : ''
                      }`}
                    >
                      <span className="font-mono text-xs text-muted-foreground">{o.sku}</span>
                      <span className="ml-2">{o.name}</span>
                      {o.category && (
                        <span className="ml-1 text-xs text-muted-foreground">· {o.category}</span>
                      )}
                    </button>
                  ))
                )}
              </div>
              {(form.errors.supply_id || form.errors.product_id) && (
                <p className="text-xs text-destructive">
                  {form.errors.supply_id ?? form.errors.product_id}
                </p>
              )}
            </div>

            {/* Warehouse */}
            {selectedItem && selectedItem.stocks.length > 0 && (
              <div className="space-y-1.5">
                <label className="text-sm font-medium">Warehouse</label>
                <Select
                  value={form.data.warehouse_id}
                  onValueChange={(v) => form.setData('warehouse_id', v)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select warehouse…" />
                  </SelectTrigger>
                  <SelectContent>
                    {selectedItem.stocks.map((s) => (
                      <SelectItem
                        key={s.warehouse_id ?? 'null'}
                        value={String(s.warehouse_id ?? '')}
                      >
                        {s.warehouse_name ?? 'Unknown'} — {s.available.toLocaleString()} available
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {availableInWarehouse !== null && (
                  <p className="flex items-center gap-1 text-xs text-muted-foreground">
                    {availableInWarehouse <= 0 && (
                      <AlertTriangle className="h-3 w-3 text-warning" />
                    )}
                    {availableInWarehouse.toLocaleString()} units available in this warehouse
                  </p>
                )}
              </div>
            )}

            {/* Quantity + Unit cost */}
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <label className="text-sm font-medium">
                  Quantity <span className="text-destructive">*</span>
                </label>
                <Input
                  type="number"
                  min="1"
                  placeholder="0"
                  value={form.data.quantity}
                  onChange={(e) => form.setData('quantity', e.target.value)}
                />
                {form.errors.quantity && (
                  <p className="text-xs text-destructive">{form.errors.quantity}</p>
                )}
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium">
                  Unit Cost <span className="text-destructive">*</span>
                </label>
                <Input
                  type="number"
                  min="0"
                  step="0.0001"
                  placeholder="0.00"
                  value={form.data.unit_cost}
                  onChange={(e) => form.setData('unit_cost', e.target.value)}
                />
                {form.errors.unit_cost && (
                  <p className="text-xs text-destructive">{form.errors.unit_cost}</p>
                )}
              </div>
            </div>

            {/* Total value preview */}
            {form.data.quantity && form.data.unit_cost && (
              <div className="rounded-md bg-destructive/5 px-3 py-2 text-sm">
                <span className="text-muted-foreground">Write-off value: </span>
                <span className="font-bold text-destructive">
                  {formatCurrency(parseFloat(form.data.quantity) * parseFloat(form.data.unit_cost))}
                </span>
              </div>
            )}

            {/* Reason */}
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Reason</label>
              <Textarea
                placeholder="Why is this stock being declared dead? (expired, damaged, lost…)"
                rows={2}
                value={form.data.reason}
                onChange={(e) => form.setData('reason', e.target.value)}
              />
            </div>

            {/* Warning */}
            <div className="flex items-start gap-2 rounded-md border border-warning/20 bg-warning/5 p-3 text-xs text-warning">
              <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
              Stock will be deducted immediately when you submit. This action cannot be undone.
            </div>

            <div className="flex justify-end gap-2 pt-1">
              <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                Cancel
              </Button>
              <Button
                type="submit"
                variant="destructive"
                disabled={
                  form.processing || !selectedId || !form.data.quantity || !form.data.unit_cost
                }
                className="gap-1.5"
              >
                <Skull className="h-4 w-4" />
                {form.processing ? 'Recording…' : 'Record & Write Off'}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
