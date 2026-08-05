import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  Calculator,
  Download,
  Package,
  Search,
  TrendingUp,
  Warehouse as WarehouseIcon,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
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
import Paginator from '@/components/Paginator';
import { formatCurrency, cn } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface ValuationItem {
  stock_id: number;
  stream: 'product' | 'supply';
  item_id: number;
  item_name: string;
  item_sku: string;
  category: string;
  warehouse: string;
  warehouse_id: number;
  current_stock: number;
  reserved_stock: number;
  available_stock: number;
  unit_cost: number;
  total_value: number;
  available_value: number;
  selling_price: number;
  potential_value: number;
  margin_pct: number;
  method: string;
}

interface Summary {
  method: string;
  total_value: number;
  product_value: number;
  supply_value: number;
  available_value: number;
  reserved_value: number;
  potential_sales_value: number;
  potential_margin: number;
  product_units: number;
  supply_units: number;
  total_skus: number;
  product_skus: number;
  supply_skus: number;
}

interface WarehouseBreakdown {
  id: number;
  name: string;
  code: string;
  product_value: number;
  supply_value: number;
  total_value: number;
  sku_count: number;
}

interface CategoryBreakdown {
  category: string;
  total_value: number;
  sku_count: number;
  units: number;
}

interface ValuationData {
  summary: Summary;
  by_warehouse: WarehouseBreakdown[];
  by_category: CategoryBreakdown[];
  items: ValuationItem[];
  method: string;
  pagination: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
  filters: Record<string, string | null>;
}

interface Props {
  valuation: ValuationData;
  warehouses: { id: number; name: string; code: string }[];
  filters: Record<string, string | undefined>;
}

export default function InventoryValuation({ valuation, warehouses, filters }: Props) {
  const [method, setMethod] = useState(filters.method ?? 'FIFO');
  const [warehouseId, setWarehouseId] = useState(filters.warehouse_id ?? 'all');
  const [stream, setStream] = useState(filters.stream ?? 'all');
  const [search, setSearch] = useState(filters.search ?? '');

  function applyFilters(overrides: Record<string, string | undefined>) {
    const params: Record<string, string | undefined> = {
      method,
      warehouse_id: warehouseId !== 'all' ? warehouseId : undefined,
      stream: stream !== 'all' ? stream : undefined,
      search: search || undefined,
      ...overrides,
    };

    router.get('/inventory/valuation', params, { preserveState: true, replace: true });
  }

  function handleExport() {
    const params = new URLSearchParams();
    if (method) params.set('method', method);
    if (warehouseId !== 'all') params.set('warehouse_id', warehouseId);
    if (stream !== 'all') params.set('stream', stream);
    if (search) params.set('search', search);

    window.location.href = `/inventory/valuation/export?${params.toString()}`;
  }

  const paginatedResponse: PaginatedResponse<ValuationItem> = {
    data: valuation.items,
    current_page: valuation.pagination.current_page,
    last_page: valuation.pagination.last_page,
    per_page: valuation.pagination.per_page,
    total: valuation.pagination.total,
    from: valuation.pagination.from ?? 0,
    to: valuation.pagination.to ?? 0,
    links: [],
  };

  return (
    <AppLayout>
      <Head title="Inventory Valuation" />
      <div className="space-y-5 p-6">
        {/* Header */}
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1 flex items-center gap-1 text-xs text-muted-foreground">
              <Link href="/inventory" className="hover:text-foreground">
                Inventory
              </Link>
              <span>/</span>
              <span>Valuation</span>
            </div>
            <h1 className="text-2xl font-bold tracking-tight flex items-center gap-2">
              <Calculator className="h-5 w-5 text-info" />
              Inventory Valuation
            </h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Stock value using FIFO, LIFO, or weighted average. Exportable to CSV.
            </p>
          </div>
          <Button onClick={handleExport} variant="outline">
            <Download className="mr-1.5 h-4 w-4" />
            Export CSV
          </Button>
        </div>

        {/* Summary stats */}
        <div className="grid grid-cols-4 gap-4">
          <StatCard
            label="Total Inventory Value"
            value={formatCurrency(valuation.summary.total_value)}
            sub={`${valuation.summary.total_skus} SKUs`}
            icon={<Calculator className="h-4 w-4" />}
            accent="info"
          />
          <StatCard
            label="Product Value"
            value={formatCurrency(valuation.summary.product_value)}
            sub={`${valuation.summary.product_skus} SKUs · ${valuation.summary.product_units} units`}
            icon={<Package className="h-4 w-4" />}
            accent="success"
          />
          <StatCard
            label="Supply Value"
            value={formatCurrency(valuation.summary.supply_value)}
            sub={`${valuation.summary.supply_skus} SKUs · ${valuation.summary.supply_units} units`}
            icon={<Package className="h-4 w-4" />}
            accent="warning"
          />
          <StatCard
            label="Potential Margin"
            value={formatCurrency(valuation.summary.potential_margin)}
            sub={`Sales: ${formatCurrency(valuation.summary.potential_sales_value)}`}
            icon={<TrendingUp className="h-4 w-4" />}
            accent="info"
          />
        </div>

        {/* Secondary stats */}
        <div className="grid grid-cols-3 gap-4">
          <Card>
            <CardContent className="p-4">
              <div className="text-xs text-muted-foreground uppercase">Available Value</div>
              <div className="mt-1 text-xl font-bold text-success">
                {formatCurrency(valuation.summary.available_value)}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="text-xs text-muted-foreground uppercase">Reserved Value</div>
              <div className="mt-1 text-xl font-bold text-warning">
                {formatCurrency(valuation.summary.reserved_value)}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="text-xs text-muted-foreground uppercase">Valuation Method</div>
              <div className="mt-1 text-xl font-bold">{valuation.method}</div>
            </CardContent>
          </Card>
        </div>

        {/* By warehouse */}
        {valuation.by_warehouse.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-sm">
                <WarehouseIcon className="h-4 w-4" />
                Valuation by Warehouse
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Warehouse</TableHead>
                    <TableHead className="text-right">Product Value</TableHead>
                    <TableHead className="text-right">Supply Value</TableHead>
                    <TableHead className="text-right">Total Value</TableHead>
                    <TableHead className="text-right">SKUs</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {valuation.by_warehouse.map((w) => (
                    <TableRow key={w.id}>
                      <TableCell className="font-medium">
                        {w.name} <span className="text-xs text-muted-foreground">({w.code})</span>
                      </TableCell>
                      <TableCell className="text-right">
                        {formatCurrency(w.product_value)}
                      </TableCell>
                      <TableCell className="text-right">{formatCurrency(w.supply_value)}</TableCell>
                      <TableCell className="text-right font-bold">
                        {formatCurrency(w.total_value)}
                      </TableCell>
                      <TableCell className="text-right">{w.sku_count}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* By category */}
        {valuation.by_category.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Valuation by Category</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Category</TableHead>
                    <TableHead className="text-right">Total Value</TableHead>
                    <TableHead className="text-right">SKUs</TableHead>
                    <TableHead className="text-right">Units</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {valuation.by_category.slice(0, 10).map((c, i) => (
                    <TableRow key={i}>
                      <TableCell className="font-medium">{c.category}</TableCell>
                      <TableCell className="text-right">{formatCurrency(c.total_value)}</TableCell>
                      <TableCell className="text-right">{c.sku_count}</TableCell>
                      <TableCell className="text-right">{c.units}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Filters */}
        <Card>
          <CardContent className="flex flex-wrap items-end gap-3 p-4">
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Method</label>
              <Select
                value={method}
                onValueChange={(v) => {
                  setMethod(v);
                  applyFilters({ method: v, page: '1' });
                }}
              >
                <SelectTrigger className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="FIFO">FIFO</SelectItem>
                  <SelectItem value="LIFO">LIFO</SelectItem>
                  <SelectItem value="WEIGHTED_AVERAGE">Weighted Average</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Warehouse</label>
              <Select
                value={warehouseId}
                onValueChange={(v) => {
                  setWarehouseId(v);
                  applyFilters({ warehouse_id: v === 'all' ? undefined : v, page: '1' });
                }}
              >
                <SelectTrigger className="w-44">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Warehouses</SelectItem>
                  {warehouses.map((w) => (
                    <SelectItem key={w.id} value={String(w.id)}>
                      {w.name} ({w.code})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Type</label>
              <Select
                value={stream}
                onValueChange={(v) => {
                  setStream(v);
                  applyFilters({ stream: v === 'all' ? undefined : v, page: '1' });
                }}
              >
                <SelectTrigger className="w-32">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Types</SelectItem>
                  <SelectItem value="product">Products</SelectItem>
                  <SelectItem value="supply">Supplies</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex-1 space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Search</label>
              <div className="relative">
                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  className="pl-8"
                  placeholder="Search by name or SKU..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') applyFilters({ page: '1' });
                  }}
                />
              </div>
            </div>
            <Button onClick={() => applyFilters({ page: '1' })}>Apply</Button>
          </CardContent>
        </Card>

        {/* Items table */}
        <Card>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Item</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>Warehouse</TableHead>
                  <TableHead className="text-right">Stock</TableHead>
                  <TableHead className="text-right">Reserved</TableHead>
                  <TableHead className="text-right">Unit Cost</TableHead>
                  <TableHead className="text-right">Total Value</TableHead>
                  <TableHead className="text-right">Sell Price</TableHead>
                  <TableHead className="text-right">Margin %</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {valuation.items.map((item) => (
                  <TableRow key={`${item.stream}-${item.stock_id}`}>
                    <TableCell>
                      <div className="font-medium">{item.item_name}</div>
                      <div className="text-xs text-muted-foreground">{item.item_sku}</div>
                    </TableCell>
                    <TableCell>
                      <Badge variant="outline">{item.stream}</Badge>
                    </TableCell>
                    <TableCell className="text-sm">{item.category}</TableCell>
                    <TableCell className="text-sm">{item.warehouse}</TableCell>
                    <TableCell className="text-right">{item.current_stock}</TableCell>
                    <TableCell className="text-right text-muted-foreground">
                      {item.reserved_stock}
                    </TableCell>
                    <TableCell className="text-right">{formatCurrency(item.unit_cost)}</TableCell>
                    <TableCell className="text-right font-bold">
                      {formatCurrency(item.total_value)}
                    </TableCell>
                    <TableCell className="text-right">
                      {item.selling_price > 0 ? formatCurrency(item.selling_price) : '—'}
                    </TableCell>
                    <TableCell className="text-right">
                      <span
                        className={cn(
                          'font-medium',
                          item.margin_pct > 30
                            ? 'text-success'
                            : item.margin_pct > 0
                              ? 'text-warning'
                              : 'text-destructive'
                        )}
                      >
                        {item.margin_pct > 0 ? `${item.margin_pct}%` : '—'}
                      </span>
                    </TableCell>
                  </TableRow>
                ))}
                {valuation.items.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={10} className="py-8 text-center text-muted-foreground">
                      No inventory items found with current stock.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
            <div className="border-t p-4">
              <Paginator pagination={paginatedResponse} />
            </div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}

function StatCard({
  label,
  value,
  sub,
  icon,
  accent,
}: {
  label: string;
  value: string;
  sub: string;
  icon: React.ReactNode;
  accent: 'info' | 'success' | 'warning' | 'destructive';
}) {
  const accentClass = {
    info: 'bg-info/10 text-info',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    destructive: 'bg-destructive/10 text-destructive',
  }[accent];

  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex items-center justify-between">
          <span className="text-xs font-medium text-muted-foreground uppercase">{label}</span>
          <span className={accentClass}>{icon}</span>
        </div>
        <div className="mt-2 text-xl font-bold">{value}</div>
        <div className="mt-0.5 text-xs text-muted-foreground">{sub}</div>
      </CardContent>
    </Card>
  );
}
