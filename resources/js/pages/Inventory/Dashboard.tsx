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
  RefreshCw, Zap, Activity, BarChart3, ScanLine, Minus, Plus, Skull,
} from 'lucide-react';
import { formatDate, formatCurrency } from '@/lib/utils';
import type { PageProps, User } from '@/types';
import axios from 'axios';
import { useState, useRef, useCallback } from 'react';

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
  const maxBar   = Math.max(...movement_trend.map(x => Math.max(Number(x.stock_in), Number(x.stock_out))), 1);
  const materialIn = supply_movement_trend.reduce((s, d) => s + Number(d.stock_in), 0);
  const materialOut = supply_movement_trend.reduce((s, d) => s + Number(d.stock_out), 0);
  const materialAdjustments = supply_movement_trend.reduce((s, d) => s + Number(d.adjustments), 0);
  const materialMaxBar = Math.max(
    ...supply_movement_trend.map(x => Math.max(Number(x.stock_in), Number(x.stock_out), Number(x.adjustments))),
    1,
  );

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
            <p className="mt-0.5 text-sm text-muted-foreground">Real-time stock visibility across all warehouses.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            {/* Barcode Scanner Input */}
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

        {/* KPI cards — left-border accent style */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <KpiCard
            accent="blue"
            icon={<Package className="h-5 w-5 text-blue-600" />}
            label="Active Products"
            value={stats.total_products.toLocaleString()}
          />
          <KpiCard
            accent="purple"
            icon={<Box className="h-5 w-5 text-purple-600" />}
            label="Active Materials"
            value={stats.total_supplies.toLocaleString()}
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
          />
        </div>

        {/* Stock health bar */}
        {low_stock.length > 0 && (
          <Card className="p-4">
            <div className="mb-2 flex items-center justify-between">
              <span className="flex items-center gap-1.5 text-sm font-medium">
                <Activity className="h-4 w-4 text-muted-foreground" />
                Stock Health Overview
              </span>
              <Link href="/inventory/movements?stock=low" className="text-xs text-blue-600 hover:underline">
                View low stock →
              </Link>
            </div>
            <div className="flex h-3 w-full overflow-hidden rounded-full bg-gray-100">
              {criticalCount > 0 && (
                <div
                  className="h-full bg-red-500 transition-all"
                  style={{ width: `${(criticalCount / stats.total_products) * 100}%` }}
                  title={`${criticalCount} out of stock`}
                />
              )}
              {lowCount > 0 && (
                <div
                  className="h-full bg-amber-400 transition-all"
                  style={{ width: `${(lowCount / stats.total_products) * 100}%` }}
                  title={`${lowCount} low stock`}
                />
              )}
              <div className="h-full flex-1 bg-green-400" title="Healthy stock" />
            </div>
            <div className="mt-2 flex flex-wrap gap-4 text-xs text-muted-foreground">
              <span className="flex items-center gap-1">
                <span className="h-2 w-3 rounded-sm bg-red-500 inline-block" /> Out of stock: {criticalCount}
              </span>
              <span className="flex items-center gap-1">
                <span className="h-2 w-3 rounded-sm bg-amber-400 inline-block" /> Low stock: {lowCount}
              </span>
              <span className="flex items-center gap-1">
                <span className="h-2 w-3 rounded-sm bg-green-400 inline-block" /> Healthy: {stats.total_products - low_stock.length}
              </span>
            </div>
          </Card>
        )}

        {/* Alert cards — 2×2 grid */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <AlertCard href="/inventory/movements?stock=low" tone="orange"
            icon={<AlertTriangle className="h-5 w-5" />}
            label="Low Stock Products" value={stats.low_stock_count} sub="below reorder point" />
          <AlertCard href={canUseMaterialsAndAdjustments ? '/inventory/supplies?status=active' : '/inventory/movements'} tone="amber"
            icon={<Box className="h-5 w-5" />}
            label="Low Stock Materials" value={stats.supply_low_stock} sub="supplies below reorder" />
          <AlertCard href={canUseMaterialsAndAdjustments ? '/inventory/adjustments?status=PENDING' : '/inventory/movements'} tone="red"
            icon={<SlidersHorizontal className="h-5 w-5" />}
            label="Pending Adjustments" value={stats.pending_adjustments} sub="awaiting approval" />
          <AlertCard href="/procurement/receiving" tone="yellow"
            icon={<CalendarClock className="h-5 w-5" />}
            label="Expiring (30 days)" value={stats.expiring_soon} sub="lots nearing expiry" />
        </div>

        {/* Non-moving / dead stock row */}
        {(stats.non_moving_products > 0 || stats.non_moving_supplies > 0) && (
          <div className="grid gap-3 sm:grid-cols-2">
            <AlertCard href="/inventory/non-moving?type=products" tone="red"
              icon={<Package className="h-5 w-5" />}
              label="Non-Moving Products" value={stats.non_moving_products} sub="no movement in 90+ days" />
            <AlertCard href="/inventory/non-moving?type=supplies" tone="red"
              icon={<Box className="h-5 w-5" />}
              label="Non-Moving Materials" value={stats.non_moving_supplies} sub="no movement in 90+ days" />
          </div>
        )}

        {/* Procurement row */}
        {canUseProcurement && (
          <div className="grid gap-3 sm:grid-cols-2">
            <AlertCard href="/procurement/requests?status=SUBMITTED" tone="blue"
              icon={<FileText className="h-5 w-5" />}
              label="Pending PR Approvals" value={stats.pending_prs} sub="purchase requests" />
            <AlertCard href="/procurement/orders?status=SENT" tone="green"
              icon={<ShoppingCart className="h-5 w-5" />}
              label="Open Purchase Orders" value={stats.open_pos} sub="awaiting receipt" />
          </div>
        )}

        {/* Movement chart + Recent activity side by side */}
        <div className="grid gap-4 lg:grid-cols-5">
          {/* 30-day chart — takes 3/5 */}
          <Card className="lg:col-span-3">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-base">
                <BarChart3 className="h-4 w-4 text-muted-foreground" />
                30-Day Movement
              </CardTitle>
              <div className="flex gap-4 text-xs">
                <span className="flex items-center gap-1">
                  <span className="h-2 w-3 rounded-sm bg-emerald-400 inline-block" />
                  <span className="font-medium text-emerald-700">+{totalIn.toLocaleString()}</span>
                  <span className="text-muted-foreground">in</span>
                </span>
                <span className="flex items-center gap-1">
                  <span className="h-2 w-3 rounded-sm bg-red-400 inline-block" />
                  <span className="font-medium text-red-700">-{totalOut.toLocaleString()}</span>
                  <span className="text-muted-foreground">out</span>
                </span>
              </div>
            </CardHeader>
            <CardContent>
              {movement_trend.length === 0 ? (
                <div className="flex h-24 items-center justify-center text-sm text-muted-foreground">
                  No movement data for this period.
                </div>
              ) : (
                <div className="flex items-end gap-px h-24 w-full overflow-hidden rounded-sm">
                  {movement_trend.map(d => (
                    <div
                      key={d.date}
                      className="group relative flex flex-1 flex-col gap-px items-center"
                      title={`${d.date} — In: ${d.stock_in}  Out: ${d.stock_out}`}
                    >
                      <div
                        style={{ height: `${(Number(d.stock_in) / maxBar) * 100}%` }}
                        className="w-full bg-emerald-400 group-hover:bg-emerald-500 rounded-t min-h-[2px] transition-colors"
                      />
                      <div
                        style={{ height: `${(Number(d.stock_out) / maxBar) * 100}%` }}
                        className="w-full bg-red-400 group-hover:bg-red-500 rounded-b min-h-[2px] transition-colors"
                      />
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Recent movements feed — takes 2/5 */}
          <Card className="lg:col-span-2">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-base">Recent Activity</CardTitle>
              <Link href="/inventory/movements" className="flex items-center gap-0.5 text-xs text-blue-600 hover:underline">
                All <ArrowRight className="h-3 w-3" />
              </Link>
            </CardHeader>
            <CardContent className="p-0">
              {recent_movements.length === 0 ? (
                <div className="py-8 text-center text-sm text-muted-foreground">No movements yet.</div>
              ) : (
                <ul className="divide-y divide-border">
                  {recent_movements.slice(0, 8).map(m => (
                    <li key={m.id} className="flex items-start gap-3 px-4 py-2.5">
                      <MovementDot type={m.type} />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-xs font-medium leading-snug">
                          {m.product?.name ?? 'Unknown product'}
                        </p>
                        <div className="mt-0.5 flex items-center gap-1.5">
                          <MovementTypePill type={m.type} />
                          <span className={`text-xs font-semibold ${m.quantity < 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                            {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                          </span>
                        </div>
                      </div>
                      <span className="shrink-0 text-[10px] text-muted-foreground whitespace-nowrap">
                        {formatDate(m.created_at)}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Material movement and specific low-stock materials */}
        <div className="grid gap-4 lg:grid-cols-5">
          <Card className="lg:col-span-3">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-base">
                <Box className="h-4 w-4 text-muted-foreground" />
                Material Movement Visual
              </CardTitle>
              <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                <span className="flex items-center gap-1">
                  <span className="inline-block h-2 w-3 rounded-sm bg-emerald-400" />
                  <span className="font-medium text-emerald-700">+{materialIn.toLocaleString()}</span>
                  <span className="text-muted-foreground">in</span>
                </span>
                <span className="flex items-center gap-1">
                  <span className="inline-block h-2 w-3 rounded-sm bg-red-400" />
                  <span className="font-medium text-red-700">-{materialOut.toLocaleString()}</span>
                  <span className="text-muted-foreground">out</span>
                </span>
                <span className="flex items-center gap-1">
                  <span className="inline-block h-2 w-3 rounded-sm bg-yellow-400" />
                  <span className="font-medium text-yellow-700">{materialAdjustments.toLocaleString()}</span>
                  <span className="text-muted-foreground">adjusted</span>
                </span>
              </div>
            </CardHeader>
            <CardContent>
              {supply_movement_trend.length === 0 ? (
                <div className="flex h-28 items-center justify-center text-sm text-muted-foreground">
                  No material movement data for this period.
                </div>
              ) : (
                <div className="flex h-28 w-full items-end gap-px overflow-hidden rounded-sm">
                  {supply_movement_trend.map(d => (
                    <div
                      key={d.date}
                      className="group relative flex flex-1 flex-col items-center gap-px"
                      title={`${d.date} — In: ${d.stock_in}  Out: ${d.stock_out}  Adjusted: ${d.adjustments}`}
                    >
                      <div
                        style={{ height: `${(Number(d.stock_in) / materialMaxBar) * 100}%` }}
                        className="min-h-[2px] w-full rounded-t bg-emerald-400 transition-colors group-hover:bg-emerald-500"
                      />
                      <div
                        style={{ height: `${(Number(d.stock_out) / materialMaxBar) * 100}%` }}
                        className="min-h-[2px] w-full bg-red-400 transition-colors group-hover:bg-red-500"
                      />
                      <div
                        style={{ height: `${(Number(d.adjustments) / materialMaxBar) * 100}%` }}
                        className="min-h-[2px] w-full rounded-b bg-yellow-400 transition-colors group-hover:bg-yellow-500"
                      />
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          <Card className="lg:col-span-2">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-base">Recent Material Movements</CardTitle>
              {canUseMaterialsAndAdjustments && (
                <Link href="/inventory/supplies" className="flex items-center gap-0.5 text-xs text-blue-600 hover:underline">
                  Materials <ArrowRight className="h-3 w-3" />
                </Link>
              )}
            </CardHeader>
            <CardContent className="p-0">
              {recent_supply_movements.length === 0 ? (
                <div className="py-8 text-center text-sm text-muted-foreground">No material movements yet.</div>
              ) : (
                <ul className="divide-y divide-border">
                  {recent_supply_movements.slice(0, 8).map(m => (
                    <li key={m.id} className="flex items-start gap-3 px-4 py-2.5">
                      <MovementDot type={m.type} />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-xs font-medium leading-snug">
                          {m.supply?.name ?? 'Unknown material'}
                        </p>
                        <div className="mt-0.5 flex items-center gap-1.5">
                          <MovementTypePill type={m.type} />
                          <span className={`text-xs font-semibold ${m.quantity < 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                            {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                          </span>
                          <span className="truncate text-[10px] text-muted-foreground">{m.warehouse?.name ?? ''}</span>
                        </div>
                      </div>
                      <span className="shrink-0 whitespace-nowrap text-[10px] text-muted-foreground">
                        {formatDate(m.created_at)}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>

        {supply_low_stock.length > 0 && (
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-base">
                <AlertTriangle className="h-4 w-4 text-amber-500" />
                Specific Low Stock Materials
              </CardTitle>
              {canUseMaterialsAndAdjustments && (
                <Link href="/inventory/supplies" className="text-xs text-blue-600 hover:underline">View all</Link>
              )}
            </CardHeader>
            <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {supply_low_stock.map(s => (
                <div key={s.id} className="rounded-md border border-amber-200 bg-amber-50/60 p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="font-mono text-[11px] text-amber-700">{s.sku}</div>
                      <div className="truncate text-sm font-semibold">{s.supply_name}</div>
                      <div className="truncate text-xs text-muted-foreground">{s.warehouse_name}</div>
                    </div>
                    <div className="text-right">
                      <div className="text-lg font-bold tabular-nums text-amber-700">{Number(s.available_stock)}</div>
                      <div className="text-[10px] uppercase text-muted-foreground">available</div>
                    </div>
                  </div>
                  <MaterialStockBar available={Number(s.available_stock)} reorderPoint={Number(s.reorder_point)} />
                  <div className="mt-2 flex justify-between text-[11px] text-muted-foreground">
                    <span>Current {Number(s.current_stock).toLocaleString()}</span>
                    <span>Reorder {Number(s.reorder_point).toLocaleString()}</span>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        )}

        {/* Low stock + expiring lots */}
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="flex items-center gap-1.5 text-base">
                <AlertTriangle className="h-4 w-4 text-amber-500" />
                Low Stock Products
              </CardTitle>
              <Link href="/inventory/movements" className="text-xs text-blue-600 hover:underline">View all</Link>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent">
                    <TableHead>Product</TableHead>
                    <TableHead>Location</TableHead>
                    <TableHead className="text-right">Available</TableHead>
                    <TableHead className="text-right">Reorder</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {low_stock.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={4} className="py-8 text-center text-sm text-muted-foreground">
                        ✓ All products above reorder point
                      </TableCell>
                    </TableRow>
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
                        <TableCell className="text-right">
                          <span className={`font-bold tabular-nums ${isCritical ? 'text-red-600' : 'text-amber-700'}`}>
                            {avail}
                          </span>
                        </TableCell>
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
                <CardTitle className="flex items-center gap-1.5 text-base">
                  <CalendarClock className="h-4 w-4 text-red-500" />Expiring Soon
                </CardTitle>
                <Link href="/procurement/receiving" className="text-xs text-blue-600 hover:underline">View all</Link>
              </CardHeader>
              <CardContent className="p-0">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent">
                      <TableHead>Product</TableHead>
                      <TableHead>Batch</TableHead>
                      <TableHead className="text-right">Qty</TableHead>
                      <TableHead>Expiry</TableHead>
                    </TableRow>
                  </TableHeader>
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
                <CardTitle className="flex items-center gap-1.5 text-base">
                  <RefreshCw className="h-4 w-4 text-muted-foreground" />Recent Movements
                </CardTitle>
                <Link href="/inventory/movements" className="text-xs text-blue-600 hover:underline">View all</Link>
              </CardHeader>
              <CardContent className="p-0">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent">
                      <TableHead>Time</TableHead>
                      <TableHead>Product</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead className="text-right">Qty</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {recent_movements.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={4} className="py-8 text-center text-sm text-muted-foreground">No movements yet.</TableCell>
                      </TableRow>
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
              <CardTitle className="flex items-center gap-1.5 text-base">
                <Warehouse className="h-4 w-4 text-muted-foreground" />
                Stock by Warehouse
              </CardTitle>
              <Link href="/warehouses" className="flex items-center gap-0.5 text-xs text-primary hover:underline">
                Manage <ArrowRight className="h-3 w-3" />
              </Link>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent">
                    <TableHead>Warehouse</TableHead>
                    <TableHead className="text-right">Product Units</TableHead>
                    <TableHead className="text-right">Stock Value</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {warehouse_stock_summary.map(wh => (
                    <TableRow key={wh.id}>
                      <TableCell>
                        <div className="font-medium">{wh.name}</div>
                        <div className="font-mono text-xs text-muted-foreground">{wh.code}</div>
                      </TableCell>
                      <TableCell className="text-right font-medium tabular-nums">{Number(wh.product_units).toLocaleString()}</TableCell>
                      <TableCell className="text-right font-medium tabular-nums text-emerald-700">{formatCurrency(Number(wh.stock_value))}</TableCell>
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
          {canUseMaterialsAndAdjustments && (
            <Link href="/inventory/adjustments"><Button variant="outline" size="sm"><SlidersHorizontal className="mr-1 h-3 w-3" />New Adjustment</Button></Link>
          )}
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

function KpiCard({ icon, label, value, accent }: {
  icon: React.ReactNode; label: string; value: string | number;
  accent: 'blue' | 'purple' | 'emerald' | 'green';
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
      </CardContent>
    </Card>
  );
}

function AlertCard({ href, icon, label, value, sub, tone }: {
  href: string; icon: React.ReactNode; label: string; value: number; sub: string;
  tone: 'orange' | 'amber' | 'blue' | 'green' | 'red' | 'yellow';
}) {
  const cls: Record<string, string> = {
    orange: 'border-orange-200 bg-orange-50 text-orange-700 hover:border-orange-300',
    amber:  'border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300',
    blue:   'border-blue-200 bg-blue-50 text-blue-700 hover:border-blue-300',
    green:  'border-green-200 bg-green-50 text-green-700 hover:border-green-300',
    red:    'border-red-200 bg-red-50 text-red-700 hover:border-red-300',
    yellow: 'border-yellow-200 bg-yellow-50 text-yellow-700 hover:border-yellow-300',
  };
  return (
    <Link href={href}>
      <Card className={`cursor-pointer border transition-all hover:shadow-sm ${cls[tone]}`}>
        <CardContent className="flex items-center justify-between p-4">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-wide opacity-70">{label}</p>
            <p className="mt-1 text-3xl font-bold tabular-nums leading-none">{value}</p>
            <p className="mt-1 text-xs opacity-60">{sub}</p>
          </div>
          <div className="opacity-50">{icon}</div>
        </CardContent>
      </Card>
    </Link>
  );
}

function MaterialStockBar({ available, reorderPoint }: { available: number; reorderPoint: number }) {
  const percent = reorderPoint > 0 ? Math.min(Math.max((available / reorderPoint) * 100, 0), 100) : 0;
  const fillClass = available <= 0 ? 'bg-red-500' : available <= reorderPoint * 0.5 ? 'bg-orange-500' : 'bg-amber-500';

  return (
    <div className="mt-3">
      <div className="h-2 overflow-hidden rounded-full bg-white">
        <div className={`h-full rounded-full ${fillClass}`} style={{ width: `${percent}%` }} />
      </div>
    </div>
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
