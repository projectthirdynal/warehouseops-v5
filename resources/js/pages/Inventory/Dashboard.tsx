import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
  Package, Warehouse, AlertTriangle, ShoppingCart, FileText, TrendingUp,
  Box, CalendarClock, SlidersHorizontal, ArrowRight, ArrowUpCircle,
  RefreshCw, Zap, Activity, ScanLine, Minus, Plus, Skull,
  Layers, Tag, MoveRight,
} from 'lucide-react';
import { formatDate, formatCurrency } from '@/lib/utils';
import type { PageProps, User } from '@/types';
import axios from 'axios';
import { useState, useRef, useCallback } from 'react';
import {
  AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
  PieChart, Pie, Cell,
} from 'recharts';

interface MovementRow {
  id: number;
  product?: { id: number; sku: string; name: string };
  warehouse?: { id: number; name: string };
  type: string;
  quantity: number;
  notes?: string;
  created_at: string;
}

interface MaterialMovementRow {
  id: number;
  supply?: { id: number; sku: string; name: string };
  warehouse?: { id: number; name: string };
  type: string;
  quantity: number;
  notes?: string;
  created_at: string;
}

interface LowStockRow {
  id: number;
  product?: { id: number; sku: string; name: string };
  warehouse?: { id: number; name: string };
  current_stock: number;
  reserved_stock: number;
  available_stock: number;
  reorder_point: number;
}

interface SupplyLowStockRow {
  id: number;
  supply_name: string;
  sku: string;
  warehouse_name: string;
  current_stock: number;
  reserved_stock: number;
  available_stock: number;
  reorder_point: number;
}

interface ExpiringLot {
  id: number;
  product_name: string;
  sku: string;
  warehouse_name: string;
  quantity_remaining: number;
  expiry_date: string;
  batch_number?: string;
}

interface WarehouseStockSummary {
  id: number;
  name: string;
  code: string;
  product_units: number;
  supply_units: number;
  product_value: number;
  supply_value: number;
  stock_value: number;
}

interface MovementTrend {
  date: string;
  stock_in: number;
  stock_out: number;
}

interface SupplyMovementTrend extends MovementTrend {
  adjustments: number;
}

interface Props {
  stats: {
    total_products: number;
    total_supplies: number;
    total_warehouses: number;
    stock_value: number;
    low_stock_count: number;
    supply_low_stock: number;
    expiring_soon: number;
    pending_adjustments: number;
    pending_prs: number;
    open_pos: number;
    non_moving_products: number;
    non_moving_supplies: number;
  };
  recent_movements: MovementRow[];
  recent_supply_movements: MaterialMovementRow[];
  low_stock: LowStockRow[];
  supply_low_stock: SupplyLowStockRow[];
  expiring_lots: ExpiringLot[];
  warehouse_stock_summary: WarehouseStockSummary[];
  movement_trend: MovementTrend[];
  supply_movement_trend: SupplyMovementTrend[];
  product_stock_value: number;
  supply_stock_value: number;
  stock_status_distribution: Record<string, number>;
  section_breakdown: Record<string, number>;
  top_product_movers: { id: number; sku: string; name: string; total_qty: number }[];
  top_supply_movers: { id: number; sku: string; name: string; total_qty: number }[];
}

interface ScannedProduct {
  status: 'found' | 'error' | 'not_found';
  product?: {
    id: number;
    sku: string;
    name: string;
    barcode: string | null;
    qr_code: string | null;
    selling_price: number;
    cost_price: number;
  };
  stock?: {
    warehouse_id: number;
    warehouse_name: string;
    current_stock: number;
    available_stock: number;
    reorder_point: number;
    is_low_stock: boolean;
  };
  message?: string;
}

export default function InventoryDashboard({
  stats, recent_movements = [], recent_supply_movements = [], low_stock = [], supply_low_stock = [],
  expiring_lots = [], warehouse_stock_summary = [], movement_trend = [], supply_movement_trend = [],
  product_stock_value, supply_stock_value, stock_status_distribution, section_breakdown,
  top_product_movers, top_supply_movers,
}: Props) {
  const { auth } = usePage<PageProps>().props;
  const role = auth?.user?.role as User['role'] | undefined;
  const warehouseRoles: User['role'][] = ['superadmin', 'admin', 'supervisor', 'warehouse'];
  const canUseProcurement = !!role && warehouseRoles.includes(role);
  const canUseMaterialsAndAdjustments = canUseProcurement || role === 'accounting';
  const canAutoApprove = role === 'superadmin' || role === 'admin' || role === 'supervisor';

  // Barcode scanner state
  const [scanValue, setScanValue] = useState('');
  const [scannedProduct, setScannedProduct] = useState<ScannedProduct | null>(null);
  const [scanLoading, setScanLoading] = useState(false);
  const [adjustQty, setAdjustQty] = useState(0);
  const [adjustReason, setAdjustReason] = useState('PHYSICAL_COUNT');
  const [adjustNotes, setAdjustNotes] = useState('');
  const [adjustLoading, setAdjustLoading] = useState(false);
  const scanInputRef = useRef<HTMLInputElement>(null);

  const totalIn  = movement_trend.reduce((s, d) => s + Number(d.stock_in), 0);
  const totalOut = movement_trend.reduce((s, d) => s + Number(d.stock_out), 0);
  const materialIn = supply_movement_trend.reduce((s, d) => s + Number(d.stock_in), 0);
  const materialOut = supply_movement_trend.reduce((s, d) => s + Number(d.stock_out), 0);

  const criticalCount = low_stock.filter(s => {
    const avail = s.available_stock ?? (s.current_stock - s.reserved_stock);
    return avail <= 0;
  }).length;
  const lowCount = low_stock.length - criticalCount;

  const handleScan = useCallback(async (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter') return;
    const barcode = scanValue.trim();
    if (!barcode) return;

    setScanLoading(true);
    try {
      const res = await axios.post('/inventory/scan', { barcode });
      setScannedProduct(res.data);
      setAdjustQty(res.data.stock?.current_stock ?? 0);
      setScanValue('');
      // Play success beep if browser supports it
      try {
        const audioCtx = new (window.AudioContext || (window as any).webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.frequency.value = 880;
        gain.gain.value = 0.1;
        osc.start();
        osc.stop(audioCtx.currentTime + 0.15);
      } catch (_) { /* ignore audio errors */ }
    } catch (err: any) {
      setScannedProduct({ status: 'error', message: err.response?.data?.message ?? 'Scan failed' });
      // Error beep
      try {
        const audioCtx = new (window.AudioContext || (window as any).webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.frequency.value = 220;
        gain.gain.value = 0.1;
        osc.start();
        osc.stop(audioCtx.currentTime + 0.3);
      } catch (_) { /* ignore audio errors */ }
    } finally {
      setScanLoading(false);
    }
  }, [scanValue]);

  const submitAdjustment = useCallback(async () => {
    if (!scannedProduct?.product) return;
    setAdjustLoading(true);
    try {
      const endpoint = canAutoApprove ? '/inventory/scan/auto-adjust' : '/inventory/scan/adjust';
      await axios.post(endpoint, {
        product_id: scannedProduct.product.id,
        warehouse_id: scannedProduct.stock?.warehouse_id,
        quantity_after: adjustQty,
        reason_code: adjustReason,
        reason_notes: adjustNotes,
      });
      setScannedProduct(null);
      setAdjustQty(0);
      setAdjustNotes('');
    } catch (err: any) {
      setScannedProduct({ status: 'error', message: err.response?.data?.message ?? 'Adjustment failed' });
    } finally {
      setAdjustLoading(false);
    }
  }, [scannedProduct, canAutoApprove, adjustQty, adjustReason, adjustNotes]);

  return (
    <AppLayout>
      <Head title="Inventory Dashboard" />

      {/* Critical alert bar */}
      {(criticalCount > 0 || stats.pending_adjustments > 0) && (
        <div className="border-b border-red-200 bg-red-50 px-6 py-2.5">
          <div className="flex flex-wrap items-center gap-x-6 gap-y-1">
            {criticalCount > 0 && (
              <span className="flex items-center gap-1.5 text-sm font-medium text-red-700">
                <AlertTriangle className="h-4 w-4 shrink-0" />
                {criticalCount} product{criticalCount > 1 ? 's' : ''} out of stock
                <Link href="/inventory/movements?stock=low" className="ml-1 underline underline-offset-2 hover:text-red-900">View →</Link>
              </span>
            )}
            {stats.pending_adjustments > 0 && (
              <span className="flex items-center gap-1.5 text-sm font-medium text-orange-700">
                <SlidersHorizontal className="h-4 w-4 shrink-0" />
                {stats.pending_adjustments} adjustment{stats.pending_adjustments > 1 ? 's' : ''} awaiting approval
                {canUseMaterialsAndAdjustments && (
                  <Link href="/inventory/adjustments?status=PENDING" className="ml-1 underline underline-offset-2 hover:text-orange-900">Review →</Link>
                )}
              </span>
            )}
          </div>
        </div>
      )}

      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Inventory Dashboard</h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {formatCurrency(product_stock_value)} products · {formatCurrency(supply_stock_value)} materials · {stats.total_warehouses} warehouses
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            {canUseMaterialsAndAdjustments && (
              <div className="flex items-center gap-2 rounded-lg border border-input bg-background px-3 py-1.5 shadow-sm focus-within:ring-1 focus-within:ring-ring">
                <ScanLine className="h-4 w-4 text-muted-foreground" />
                <input
                  ref={scanInputRef}
                  type="text"
                  placeholder="Scan barcode / QR / SKU..."
                  className="w-52 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                  value={scanValue}
                  onChange={(e) => setScanValue(e.target.value)}
                  onKeyDown={handleScan}
                  disabled={scanLoading}
                  autoFocus
                />
                {scanLoading && <RefreshCw className="h-3 w-3 animate-spin text-muted-foreground" />}
              </div>
            )}
            {canUseMaterialsAndAdjustments && (
              <Link href="/inventory/adjustments">
                <Button variant="outline" size="sm">
                  <SlidersHorizontal className="mr-1.5 h-4 w-4" />
                  Adjustments
                  {stats.pending_adjustments > 0 && (
                    <span className="ml-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-orange-500 text-[10px] font-bold text-white">
                      {stats.pending_adjustments}
                    </span>
                  )}
                </Button>
              </Link>
            )}
            {canUseProcurement && (
              <>
                <Link href="/procurement/requests/create">
                  <Button variant="outline" size="sm"><FileText className="mr-1.5 h-4 w-4" />New PR</Button>
                </Link>
                <Link href="/procurement/orders/create">
                  <Button size="sm"><ShoppingCart className="mr-1.5 h-4 w-4" />New PO</Button>
                </Link>
              </>
            )}
          </div>
        </div>

        {/* KPI cards */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <KpiCard
            accent="blue"
            icon={<Package className="h-5 w-5 text-blue-600" />}
            label="Active Products"
            value={stats.total_products.toLocaleString()}
            sub={formatCurrency(product_stock_value)}
          />
          <KpiCard
            accent="purple"
            icon={<Box className="h-5 w-5 text-purple-600" />}
            label="Active Materials"
            value={stats.total_supplies.toLocaleString()}
            sub={formatCurrency(supply_stock_value)}
          />
          <KpiCard
            accent="emerald"
            icon={<Warehouse className="h-5 w-5 text-emerald-600" />}
            label="Warehouses"
            value={stats.total_warehouses.toLocaleString()}
          />
          <KpiCard
            accent="green"
            icon={<TrendingUp className="h-5 w-5 text-green-600" />}
            label="Total Stock Value"
            value={formatCurrency(stats.stock_value)}
            sub={`${formatCurrency(product_stock_value)} products + ${formatCurrency(supply_stock_value)} supplies`}
          />
        </div>

        {/* Alert pills row */}
        <div className="flex flex-wrap gap-2">
          {stats.low_stock_count > 0 && (
            <AlertPill href="/inventory/movements?stock=low" tone="orange"
              icon={<AlertTriangle className="h-3.5 w-3.5" />}
              label="Low Stock Products" value={stats.low_stock_count} />
          )}
          {stats.supply_low_stock > 0 && (
            <AlertPill href="/inventory/supplies" tone="amber"
              icon={<Box className="h-3.5 w-3.5" />}
              label="Low Stock Materials" value={stats.supply_low_stock} />
          )}
          {stats.non_moving_products > 0 && (
            <AlertPill href="/inventory/non-moving?type=products" tone="red"
              icon={<Package className="h-3.5 w-3.5" />}
              label="Non-Moving Products" value={stats.non_moving_products} />
          )}
          {stats.non_moving_supplies > 0 && (
            <AlertPill href="/inventory/non-moving?type=supplies" tone="red"
              icon={<Box className="h-3.5 w-3.5" />}
              label="Non-Moving Materials" value={stats.non_moving_supplies} />
          )}
          {stats.expiring_soon > 0 && (
            <AlertPill href="/procurement/receiving" tone="yellow"
              icon={<CalendarClock className="h-3.5 w-3.5" />}
              label="Expiring Soon" value={stats.expiring_soon} />
          )}
          {canUseProcurement && stats.pending_prs > 0 && (
            <AlertPill href="/procurement/requests?status=SUBMITTED" tone="blue"
              icon={<FileText className="h-3.5 w-3.5" />}
              label="Pending PRs" value={stats.pending_prs} />
          )}
          {canUseProcurement && stats.open_pos > 0 && (
            <AlertPill href="/procurement/orders?status=SENT" tone="green"
              icon={<ShoppingCart className="h-3.5 w-3.5" />}
              label="Open POs" value={stats.open_pos} />
          )}
        </div>

        {/* Stock health bar */}
        {low_stock.length > 0 && (
          <Card className="p-4">
            <div className="mb-2 flex items-center justify-between">
              <span className="flex items-center gap-1.5 text-sm font-medium">
                <Activity className="h-4 w-4 text-muted-foreground" />
                Stock Health Overview
              </span>
              <Link href="/inventory/movements?stock=low" className="text-xs text-blue-600 hover:underline">View low stock →</Link>
            </div>
            <div className="flex h-3 w-full overflow-hidden rounded-full bg-gray-100">
              {criticalCount > 0 && (
                <div className="h-full bg-red-500 transition-all" style={{ width: `${(criticalCount / stats.total_products) * 100}%` }} />
              )}
              {lowCount > 0 && (
                <div className="h-full bg-amber-400 transition-all" style={{ width: `${(lowCount / stats.total_products) * 100}%` }} />
              )}
              <div className="h-full flex-1 bg-green-400" />
            </div>
            <div className="mt-2 flex flex-wrap gap-4 text-xs text-muted-foreground">
              <span className="flex items-center gap-1"><span className="h-2 w-3 rounded-sm bg-red-500 inline-block" /> Out of stock: {criticalCount}</span>
              <span className="flex items-center gap-1"><span className="h-2 w-3 rounded-sm bg-amber-400 inline-block" /> Low stock: {lowCount}</span>
              <span className="flex items-center gap-1"><span className="h-2 w-3 rounded-sm bg-green-400 inline-block" /> Healthy: {stats.total_products - low_stock.length}</span>
            </div>
          </Card>
        )}

        {/* Charts row — Product trend | Supply trend | Status donut */}
        <div className="grid gap-4 lg:grid-cols-3">
          {/* Product Movement Trend */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                <Package className="h-4 w-4 text-blue-500" />
                Product Movement (30d)
              </CardTitle>
              <span className="text-xs text-muted-foreground">+{totalIn.toLocaleString()} in · -{totalOut.toLocaleString()} out</span>
            </CardHeader>
            <CardContent>
              {movement_trend.length === 0 ? (
                <div className="flex h-48 items-center justify-center text-sm text-muted-foreground">No product movement data.</div>
              ) : (
                <ResponsiveContainer width="100%" height={180}>
                  <AreaChart data={movement_trend} margin={{ top: 5, right: 5, left: -20, bottom: 0 }}>
                    <defs>
                      <linearGradient id="pin" x1="0" y1="0" x2="0" y2="1"><stop offset="5%" stopColor="#34d399" stopOpacity={0.3}/><stop offset="95%" stopColor="#34d399" stopOpacity={0}/></linearGradient>
                      <linearGradient id="pout" x1="0" y1="0" x2="0" y2="1"><stop offset="5%" stopColor="#f87171" stopOpacity={0.3}/><stop offset="95%" stopColor="#f87171" stopOpacity={0}/></linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                    <XAxis dataKey="date" tick={{ fontSize: 10 }} tickFormatter={(v: string) => v.slice(5)} interval={Math.floor(movement_trend.length / 6)} />
                    <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
                    <Tooltip contentStyle={{ fontSize: 12, borderRadius: 6 }} formatter={(v: number) => v.toLocaleString()} />
                    <Area type="monotone" dataKey="stock_in" stroke="#34d399" fill="url(#pin)" strokeWidth={2} name="Stock In" />
                    <Area type="monotone" dataKey="stock_out" stroke="#f87171" fill="url(#pout)" strokeWidth={2} name="Stock Out" />
                  </AreaChart>
                </ResponsiveContainer>
              )}
            </CardContent>
          </Card>

          {/* Supply Movement Trend */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                <Box className="h-4 w-4 text-purple-500" />
                Material Movement (30d)
              </CardTitle>
              <span className="text-xs text-muted-foreground">+{materialIn.toLocaleString()} in · -{materialOut.toLocaleString()} out</span>
            </CardHeader>
            <CardContent>
              {supply_movement_trend.length === 0 ? (
                <div className="flex h-48 items-center justify-center text-sm text-muted-foreground">No material movement data.</div>
              ) : (
                <ResponsiveContainer width="100%" height={180}>
                  <AreaChart data={supply_movement_trend} margin={{ top: 5, right: 5, left: -20, bottom: 0 }}>
                    <defs>
                      <linearGradient id="min" x1="0" y1="0" x2="0" y2="1"><stop offset="5%" stopColor="#34d399" stopOpacity={0.3}/><stop offset="95%" stopColor="#34d399" stopOpacity={0}/></linearGradient>
                      <linearGradient id="mout" x1="0" y1="0" x2="0" y2="1"><stop offset="5%" stopColor="#f87171" stopOpacity={0.3}/><stop offset="95%" stopColor="#f87171" stopOpacity={0}/></linearGradient>
                      <linearGradient id="madj" x1="0" y1="0" x2="0" y2="1"><stop offset="5%" stopColor="#facc15" stopOpacity={0.3}/><stop offset="95%" stopColor="#facc15" stopOpacity={0}/></linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                    <XAxis dataKey="date" tick={{ fontSize: 10 }} tickFormatter={(v: string) => v.slice(5)} interval={Math.floor(supply_movement_trend.length / 6)} />
                    <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
                    <Tooltip contentStyle={{ fontSize: 12, borderRadius: 6 }} formatter={(v: number) => v.toLocaleString()} />
                    <Area type="monotone" dataKey="stock_in" stroke="#34d399" fill="url(#min)" strokeWidth={2} name="Stock In" />
                    <Area type="monotone" dataKey="stock_out" stroke="#f87171" fill="url(#mout)" strokeWidth={2} name="Stock Out" />
                    <Area type="monotone" dataKey="adjustments" stroke="#facc15" fill="url(#madj)" strokeWidth={2} name="Adjusted" />
                  </AreaChart>
                </ResponsiveContainer>
              )}
            </CardContent>
          </Card>

          {/* Stock Status Donut + Section Breakdown */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                <Tag className="h-4 w-4 text-amber-500" />
                Material Status
              </CardTitle>
              <Link href="/inventory/supplies" className="text-xs text-blue-600 hover:underline">View all</Link>
            </CardHeader>
            <CardContent>
              <div className="flex h-48 items-center justify-center">
                {Object.keys(stock_status_distribution).length === 0 ? (
                  <span className="text-sm text-muted-foreground">No status data.</span>
                ) : (
                  <ResponsiveContainer width="100%" height={160}>
                    <PieChart>
                      <Pie
                        data={[
                          { name: 'Moving', value: stock_status_distribution.MOVING ?? 0, color: '#34d399' },
                          { name: 'Non-Moving', value: stock_status_distribution.NON_MOVING ?? 0, color: '#facc15' },
                          { name: 'Dead', value: stock_status_distribution.DEAD ?? 0, color: '#f87171' },
                        ].filter(d => d.value > 0)}
                        cx="50%" cy="50%"
                        innerRadius={40} outerRadius={60}
                        paddingAngle={3}
                        dataKey="value"
                        label={({ name, value }) => value > 0 ? `${name}: ${value}` : ''}
                        labelLine={false}
                      >
                        {[
                          { name: 'Moving', value: stock_status_distribution.MOVING ?? 0, color: '#34d399' },
                          { name: 'Non-Moving', value: stock_status_distribution.NON_MOVING ?? 0, color: '#facc15' },
                          { name: 'Dead', value: stock_status_distribution.DEAD ?? 0, color: '#f87171' },
                        ].filter(d => d.value > 0).map((entry, index) => (
                          <Cell key={`cell-${index}`} fill={entry.color} />
                        ))}
                      </Pie>
                      <Tooltip contentStyle={{ fontSize: 12, borderRadius: 6 }} formatter={(v: number, n: string) => [`${v} items`, n]} />
                    </PieChart>
                  </ResponsiveContainer>
                )}
              </div>
              {/* Section breakdown mini pills */}
              <div className="mt-2 flex flex-wrap gap-2 justify-center">
                {Object.entries(section_breakdown).map(([section, count]) => (
                  <div key={section} className="flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs">
                    <Layers className="h-3 w-3 text-muted-foreground" />
                    <span className="font-medium">{section}</span>
                    <span className="text-muted-foreground">{Number(count).toLocaleString()}</span>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Top Movers + Recent Activity */}
        <div className="grid gap-4 lg:grid-cols-3">
          {/* Top Product Movers */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                <MoveRight className="h-4 w-4 text-blue-500" />
                Top Product Movers (30d)
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              {top_product_movers.length === 0 ? (
                <div className="py-6 text-center text-sm text-muted-foreground">No movement data.</div>
              ) : (
                <ul className="divide-y divide-border">
                  {top_product_movers.map((m, i) => (
                    <li key={m.id} className="flex items-center gap-3 px-4 py-2.5">
                      <span className="flex h-5 w-5 items-center justify-center rounded-full bg-muted text-[10px] font-bold">{i + 1}</span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-xs font-medium">{m.name}</p>
                        <p className="text-[10px] font-mono text-muted-foreground">{m.sku}</p>
                      </div>
                      <span className="text-xs font-semibold tabular-nums text-blue-600">{Number(m.total_qty).toLocaleString()} units</span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>

          {/* Top Supply Movers */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                <MoveRight className="h-4 w-4 text-purple-500" />
                Top Material Movers (30d)
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              {top_supply_movers.length === 0 ? (
                <div className="py-6 text-center text-sm text-muted-foreground">No movement data.</div>
              ) : (
                <ul className="divide-y divide-border">
                  {top_supply_movers.map((m, i) => (
                    <li key={m.id} className="flex items-center gap-3 px-4 py-2.5">
                      <span className="flex h-5 w-5 items-center justify-center rounded-full bg-muted text-[10px] font-bold">{i + 1}</span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-xs font-medium">{m.name}</p>
                        <p className="text-[10px] font-mono text-muted-foreground">{m.sku}</p>
                      </div>
                      <span className="text-xs font-semibold tabular-nums text-purple-600">{Number(m.total_qty).toLocaleString()} units</span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>

          {/* Recent Activity Feed */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                <RefreshCw className="h-4 w-4 text-muted-foreground" />
                Recent Activity
              </CardTitle>
              <Link href="/inventory/movements" className="flex items-center gap-0.5 text-xs text-blue-600 hover:underline">All <ArrowRight className="h-3 w-3" /></Link>
            </CardHeader>
            <CardContent className="p-0">
              {recent_movements.length === 0 && recent_supply_movements.length === 0 ? (
                <div className="py-6 text-center text-sm text-muted-foreground">No recent movements.</div>
              ) : (
                <ul className="divide-y divide-border max-h-64 overflow-y-auto">
                  {[...recent_movements, ...recent_supply_movements]
                    .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
                    .slice(0, 10)
                    .map(m => {
                      const isSupply = 'supply' in m;
                      const item = isSupply ? (m as MaterialMovementRow).supply : (m as MovementRow).product;
                      return (
                        <li key={`${isSupply ? 's' : 'p'}-${m.id}`} className="flex items-start gap-3 px-4 py-2.5">
                          <MovementDot type={m.type} />
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-xs font-medium">{item?.name ?? 'Unknown'}</p>
                            <div className="mt-0.5 flex items-center gap-1.5">
                              <MovementTypePill type={m.type} />
                              <span className={`text-xs font-semibold ${m.quantity < 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                                {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                              </span>
                            </div>
                          </div>
                          <span className="shrink-0 text-[10px] text-muted-foreground whitespace-nowrap">{formatDate(m.created_at)}</span>
                        </li>
                      );
                    })}
                  </ul>
              )}
            </CardContent>
          </Card>
        </div>

        {supply_low_stock.length > 0 && (
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                <AlertTriangle className="h-4 w-4 text-amber-500" />
                Low Stock Materials
              </CardTitle>
              {canUseMaterialsAndAdjustments && (
                <Link href="/inventory/supplies" className="text-xs text-blue-600 hover:underline">View all</Link>
              )}
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader><TableRow className="hover:bg-transparent"><TableHead>SKU</TableHead><TableHead>Material</TableHead><TableHead className="text-right">Available</TableHead><TableHead className="text-right">Reorder</TableHead></TableRow></TableHeader>
                <TableBody>
                  {supply_low_stock.map(s => {
                    const avail = Number(s.available_stock);
                    const isCritical = avail <= 0;
                    return (
                      <TableRow key={s.id} className={isCritical ? 'bg-red-50 hover:bg-red-50' : 'bg-amber-50/60 hover:bg-amber-50'}>
                        <TableCell className="font-mono text-[11px] text-muted-foreground">{s.sku}</TableCell>
                        <TableCell className="text-sm font-medium">{s.supply_name}</TableCell>
                        <TableCell className={`text-right font-bold tabular-nums ${isCritical ? 'text-red-600' : 'text-amber-700'}`}>{avail}</TableCell>
                        <TableCell className="text-right font-mono text-sm text-muted-foreground">{Number(s.reorder_point)}</TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Low stock + expiring lots */}
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                <AlertTriangle className="h-4 w-4 text-amber-500" />
                Low Stock Products
              </CardTitle>
              <Link href="/inventory/movements" className="text-xs text-blue-600 hover:underline">View all</Link>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader><TableRow className="hover:bg-transparent"><TableHead>Product</TableHead><TableHead>Location</TableHead><TableHead className="text-right">Available</TableHead><TableHead className="text-right">Reorder</TableHead></TableRow></TableHeader>
                <TableBody>
                  {low_stock.length === 0 ? (
                    <TableRow><TableCell colSpan={4} className="py-8 text-center text-sm text-muted-foreground">All products above reorder point</TableCell></TableRow>
                  ) : low_stock.map(s => {
                    const avail = s.available_stock ?? (s.current_stock - s.reserved_stock);
                    const isCritical = avail <= 0;
                    return (
                      <TableRow key={s.id} className={isCritical ? 'bg-red-50 hover:bg-red-50' : 'bg-amber-50/60 hover:bg-amber-50'}>
                        <TableCell>
                          <Link href={`/products/${s.product?.id}`} className="group">
                            <div className="font-mono text-[11px] text-blue-600 group-hover:underline">{s.product?.sku}</div>
                            <div className="text-sm font-medium leading-tight">{s.product?.name}</div>
                          </Link>
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">{s.warehouse?.name ?? '—'}</TableCell>
                        <TableCell className="text-right"><span className={`font-bold tabular-nums ${isCritical ? 'text-red-600' : 'text-amber-700'}`}>{avail}</span></TableCell>
                        <TableCell className="text-right font-mono text-sm text-muted-foreground">{s.reorder_point}</TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          {expiring_lots.length > 0 ? (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                  <CalendarClock className="h-4 w-4 text-red-500" />Expiring Soon
                </CardTitle>
                <Link href="/procurement/receiving" className="text-xs text-blue-600 hover:underline">View all</Link>
              </CardHeader>
              <CardContent className="p-0">
                <Table>
                  <TableHeader><TableRow className="hover:bg-transparent"><TableHead>Product</TableHead><TableHead>Batch</TableHead><TableHead className="text-right">Qty</TableHead><TableHead>Expiry</TableHead></TableRow></TableHeader>
                  <TableBody>
                    {expiring_lots.map(lot => {
                      const daysLeft = Math.ceil((new Date(lot.expiry_date).getTime() - Date.now()) / 86400000);
                      const urgent = daysLeft <= 7;
                      return (
                        <TableRow key={lot.id} className={urgent ? 'bg-red-50 hover:bg-red-50' : ''}>
                          <TableCell>
                            <div className="font-mono text-[11px] text-muted-foreground">{lot.sku}</div>
                            <div className="text-sm font-medium">{lot.product_name}</div>
                          </TableCell>
                          <TableCell className="font-mono text-xs">{lot.batch_number ?? '—'}</TableCell>
                          <TableCell className="text-right font-medium tabular-nums">{Number(lot.quantity_remaining).toLocaleString()}</TableCell>
                          <TableCell>
                            <div className="text-sm">{lot.expiry_date}</div>
                            <div className={`text-xs font-semibold ${urgent ? 'text-red-600' : 'text-orange-500'}`}>{daysLeft}d left</div>
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          ) : (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                  <RefreshCw className="h-4 w-4 text-muted-foreground" />Recent Movements
                </CardTitle>
                <Link href="/inventory/movements" className="text-xs text-blue-600 hover:underline">View all</Link>
              </CardHeader>
              <CardContent className="p-0">
                <Table>
                  <TableHeader><TableRow className="hover:bg-transparent"><TableHead>Time</TableHead><TableHead>Product</TableHead><TableHead>Type</TableHead><TableHead className="text-right">Qty</TableHead></TableRow></TableHeader>
                  <TableBody>
                    {recent_movements.length === 0 ? (
                      <TableRow><TableCell colSpan={4} className="py-8 text-center text-sm text-muted-foreground">No movements yet.</TableCell></TableRow>
                    ) : recent_movements.slice(0, 8).map(m => (
                      <TableRow key={m.id}>
                        <TableCell className="whitespace-nowrap text-xs text-muted-foreground">{formatDate(m.created_at)}</TableCell>
                        <TableCell className="max-w-[140px] truncate text-sm">{m.product?.name ?? '—'}</TableCell>
                        <TableCell><MovementTypePill type={m.type} /></TableCell>
                        <TableCell className={`text-right font-medium tabular-nums ${m.quantity < 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                          {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          )}
        </div>

        {/* Warehouse breakdown */}
        {warehouse_stock_summary.length > 0 && (
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                <Warehouse className="h-4 w-4 text-muted-foreground" />
                Stock by Warehouse
              </CardTitle>
              <Link href="/warehouses" className="flex items-center gap-0.5 text-xs text-primary hover:underline">Manage <ArrowRight className="h-3 w-3" /></Link>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader><TableRow className="hover:bg-transparent">
                  <TableHead>Warehouse</TableHead>
                  <TableHead className="text-right">Products</TableHead>
                  <TableHead className="text-right">Materials</TableHead>
                  <TableHead className="text-right">Product Value</TableHead>
                  <TableHead className="text-right">Material Value</TableHead>
                  <TableHead className="text-right">Total Value</TableHead>
                </TableRow></TableHeader>
                <TableBody>
                  {warehouse_stock_summary.map(wh => (
                    <TableRow key={wh.id}>
                      <TableCell>
                        <div className="font-medium">{wh.name}</div>
                        <div className="font-mono text-xs text-muted-foreground">{wh.code}</div>
                      </TableCell>
                      <TableCell className="text-right font-medium tabular-nums">{Number(wh.product_units).toLocaleString()}</TableCell>
                      <TableCell className="text-right font-medium tabular-nums">{Number(wh.supply_units).toLocaleString()}</TableCell>
                      <TableCell className="text-right font-medium tabular-nums text-emerald-700">{formatCurrency(Number(wh.product_value))}</TableCell>
                      <TableCell className="text-right font-medium tabular-nums text-purple-700">{formatCurrency(Number(wh.supply_value))}</TableCell>
                      <TableCell className="text-right font-bold tabular-nums text-emerald-800">{formatCurrency(Number(wh.product_value) + Number(wh.supply_value))}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Quick actions footer */}
        <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-muted/40 px-4 py-3">
          <Zap className="h-4 w-4 text-muted-foreground shrink-0" />
          <span className="text-xs font-medium text-muted-foreground mr-1">Quick:</span>
          {canUseProcurement && (
            <>
              <Link href="/procurement/requests/create"><Button variant="outline" size="sm"><FileText className="mr-1 h-3 w-3" />New PR</Button></Link>
              <Link href="/procurement/orders/create"><Button variant="outline" size="sm"><ShoppingCart className="mr-1 h-3 w-3" />New PO</Button></Link>
            </>
          )}
          <Link href="/inventory/movements"><Button variant="outline" size="sm"><ArrowUpCircle className="mr-1 h-3 w-3" />View Movements</Button></Link>
          <Link href="/inventory/non-moving"><Button variant="outline" size="sm"><AlertTriangle className="mr-1 h-3 w-3" />Non-Moving</Button></Link>
          <Link href="/inventory/dead-stock"><Button variant="outline" size="sm"><Skull className="mr-1 h-3 w-3" />Dead Stock</Button></Link>
          {canUseMaterialsAndAdjustments && (
            <Link href="/inventory/supplies"><Button variant="outline" size="sm"><Box className="mr-1 h-3 w-3" />Materials</Button></Link>
          )}
        </div>
      </div>

      {/* Scanned Product Floating Card */}
      {scannedProduct && scannedProduct.status === 'found' && scannedProduct.product && (
        <div className="fixed bottom-6 right-6 z-50 w-80 animate-in slide-in-from-bottom-2 fade-in duration-300">
          <Card className="border shadow-xl">
            <CardHeader className="pb-3">
              <div className="flex items-start justify-between">
                <div>
                  <CardTitle className="text-base">{scannedProduct.product.name}</CardTitle>
                  <p className="mt-0.5 text-xs text-muted-foreground font-mono">{scannedProduct.product.sku}</p>
                </div>
                <button
                  onClick={() => { setScannedProduct(null); scanInputRef.current?.focus(); }}
                  className="rounded p-1 text-muted-foreground hover:bg-muted"
                >
                  ×
                </button>
              </div>
            </CardHeader>
            <CardContent className="space-y-4 pb-4">
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Current Stock</span>
                <Badge variant={scannedProduct.stock?.is_low_stock ? 'destructive' : 'default'}>
                  {scannedProduct.stock?.current_stock ?? 0}
                </Badge>
              </div>
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Available</span>
                <span className="font-medium">{scannedProduct.stock?.available_stock ?? 0}</span>
              </div>
              {scannedProduct.stock && scannedProduct.stock.reorder_point > 0 && (
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">Reorder Point</span>
                  <span className="font-medium">{scannedProduct.stock.reorder_point}</span>
                </div>
              )}

              <div className="rounded-lg border bg-muted/50 p-3 space-y-3">
                <label className="text-xs font-medium text-muted-foreground">New Physical Quantity</label>
                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="icon"
                    className="h-8 w-8"
                    onClick={() => setAdjustQty(Math.max(0, adjustQty - 1))}
                  >
                    <Minus className="h-3 w-3" />
                  </Button>
                  <input
                    type="number"
                    min={0}
                    value={adjustQty}
                    onChange={(e) => setAdjustQty(Math.max(0, parseInt(e.target.value) || 0))}
                    className="h-8 w-20 rounded border bg-background px-2 text-center text-sm tabular-nums outline-none focus:ring-1 focus:ring-ring"
                  />
                  <Button
                    variant="outline"
                    size="icon"
                    className="h-8 w-8"
                    onClick={() => setAdjustQty(adjustQty + 1)}
                  >
                    <Plus className="h-3 w-3" />
                  </Button>
                </div>

                <div className="space-y-2">
                  <label className="text-xs font-medium text-muted-foreground">Reason</label>
                  <Select value={adjustReason} onValueChange={setAdjustReason}>
                    <SelectTrigger className="h-8 w-full text-xs">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="CYCLE_COUNT">Cycle Count</SelectItem>
                      <SelectItem value="PHYSICAL_COUNT">Physical Count</SelectItem>
                      <SelectItem value="DAMAGE">Damage</SelectItem>
                      <SelectItem value="THEFT">Theft</SelectItem>
                      <SelectItem value="RETURN_TO_STOCK">Return to Stock</SelectItem>
                      <SelectItem value="SYSTEM_ERROR">System Error</SelectItem>
                      <SelectItem value="OTHER">Other</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <label className="text-xs font-medium text-muted-foreground">Notes</label>
                  <Input
                    type="text"
                    placeholder="Optional notes..."
                    value={adjustNotes}
                    onChange={(e) => setAdjustNotes(e.target.value)}
                    className="h-8 text-xs"
                  />
                </div>

                <Button
                  className="w-full"
                  size="sm"
                  onClick={submitAdjustment}
                  disabled={adjustLoading}
                >
                  {adjustLoading ? 'Submitting…' : canAutoApprove ? 'Update Stock' : 'Submit for Approval'}
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Scan Error Toast */}
      {scannedProduct && scannedProduct.status === 'error' && (
        <div className="fixed bottom-6 right-6 z-50 w-80 animate-in slide-in-from-bottom-2 fade-in duration-300">
          <Card className="border-red-200 bg-red-50 shadow-xl">
            <CardContent className="flex items-center gap-3 py-4">
              <AlertTriangle className="h-5 w-5 text-red-600 shrink-0" />
              <div className="flex-1">
                <p className="text-sm font-medium text-red-800">
                  {scannedProduct.message ?? 'Scan failed'}
                </p>
              </div>
              <button
                onClick={() => { setScannedProduct(null); scanInputRef.current?.focus(); }}
                className="rounded p-1 text-red-600 hover:bg-red-100"
              >
                ×
              </button>
            </CardContent>
          </Card>
        </div>
      )}
    </AppLayout>
  );
}

function KpiCard({ icon, label, value, accent, sub }: {
  icon: React.ReactNode; label: string; value: string | number;
  accent: 'blue' | 'purple' | 'emerald' | 'green';
  sub?: string;
}) {
  const borderCls = { blue: 'border-l-primary', purple: 'border-l-purple-500', emerald: 'border-l-emerald-500', green: 'border-l-green-500' }[accent];
  return (
    <Card className={`border-l-4 ${borderCls}`}>
      <CardContent className="p-4">
        <div className="mb-2 flex items-center gap-2">
          {icon}
          <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</span>
        </div>
        <span className="text-xl font-bold tabular-nums">{value}</span>
        {sub && <p className="mt-1 text-[11px] text-muted-foreground truncate" title={sub}>{sub}</p>}
      </CardContent>
    </Card>
  );
}

function AlertPill({ href, icon, label, value, tone }: {
  href: string; icon: React.ReactNode; label: string; value: number;
  tone: 'orange' | 'amber' | 'blue' | 'green' | 'red' | 'yellow';
}) {
  const cls: Record<string, string> = {
    orange: 'bg-orange-100 text-orange-700 border-orange-200',
    amber:  'bg-amber-100 text-amber-700 border-amber-200',
    blue:   'bg-blue-100 text-blue-700 border-blue-200',
    green:  'bg-green-100 text-green-700 border-green-200',
    red:    'bg-red-100 text-red-700 border-red-200',
    yellow: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  };
  return (
    <Link href={href}>
      <div className={`flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-all hover:shadow-sm ${cls[tone]}`}>
        {icon}
        <span className="font-medium">{label}</span>
        <span className="inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-white/60 px-1 text-[10px] font-bold">{value}</span>
      </div>
    </Link>
  );
}

function MovementTypePill({ type }: { type: string }) {
  const cls: Record<string, string> = {
    STOCK_IN:    'bg-emerald-100 text-emerald-700',
    STOCK_OUT:   'bg-red-100 text-red-700',
    ADJUSTMENT:  'bg-yellow-100 text-yellow-700',
    RETURN:      'bg-blue-100 text-blue-700',
    RESERVATION: 'bg-purple-100 text-purple-700',
    RELEASE:     'bg-indigo-100 text-indigo-700',
  };
  const label: Record<string, string> = {
    STOCK_IN: 'In', STOCK_OUT: 'Out', ADJUSTMENT: 'Adj',
    RETURN: 'Return', RESERVATION: 'Rsrv', RELEASE: 'Release',
  };
  return (
    <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${cls[type] ?? 'bg-gray-100 text-gray-700'}`}>
      {label[type] ?? type}
    </span>
  );
}

function MovementDot({ type }: { type: string }) {
  const cls: Record<string, string> = {
    STOCK_IN:    'bg-emerald-500',
    STOCK_OUT:   'bg-red-500',
    ADJUSTMENT:  'bg-yellow-500',
    RETURN:      'bg-blue-500',
    RESERVATION: 'bg-purple-500',
    RELEASE:     'bg-indigo-500',
  };
  return <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${cls[type] ?? 'bg-gray-400'}`} />;
}
