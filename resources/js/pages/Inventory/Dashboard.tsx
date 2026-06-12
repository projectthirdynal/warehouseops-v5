import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
  Warehouse, AlertTriangle, ShoppingCart, FileText, TrendingUp,
  Box, SlidersHorizontal, ArrowRight, ArrowUpCircle,
  RefreshCw, Zap, Tag, MoveRight, Skull, Layers,
} from 'lucide-react';
import { formatDate, formatCurrency } from '@/lib/utils';
import type { PageProps, User } from '@/types';
import {
  AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
  PieChart, Pie, Cell,
} from 'recharts';

interface MovementRow {
  id: number;
  supply?: { id: number; sku: string; name: string };
  warehouse?: { id: number; name: string };
  type: string;
  quantity: number;
  notes?: string;
  created_at: string;
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

interface WarehouseStockSummary {
  id: number;
  name: string;
  code: string;
  supply_units: number;
  supply_value: number;
}

interface SupplyMovementTrend {
  date: string;
  stock_in: number;
  stock_out: number;
  adjustments: number;
}

interface Props {
  stats: {
    total_supplies: number;
    total_warehouses: number;
    stock_value: number;
    supply_low_stock: number;
    pending_adjustments: number;
    pending_prs: number;
    open_pos: number;
    non_moving_supplies: number;
  };
  recent_supply_movements: MovementRow[];
  supply_low_stock: SupplyLowStockRow[];
  supply_movement_trend: SupplyMovementTrend[];
  warehouse_stock_summary: WarehouseStockSummary[];
  supply_stock_value: number;
  stock_status_distribution: Record<string, number>;
  section_breakdown: Record<string, number>;
  top_supply_movers: { id: number; sku: string; name: string; total_qty: number }[];
}

export default function InventoryDashboard({
  stats, recent_supply_movements = [], supply_low_stock = [],
  warehouse_stock_summary = [], supply_movement_trend = [],
  supply_stock_value, stock_status_distribution, section_breakdown,
  top_supply_movers,
}: Props) {
  const { auth } = usePage<PageProps>().props;
  const role = auth?.user?.role as User['role'] | undefined;
  const warehouseRoles: User['role'][] = ['superadmin', 'admin', 'supervisor', 'warehouse'];
  const canUseProcurement = !!role && warehouseRoles.includes(role);
  const canUseMaterialsAndAdjustments = canUseProcurement || role === 'accounting';

  const materialIn = supply_movement_trend.reduce((s, d) => s + Number(d.stock_in), 0);
  const materialOut = supply_movement_trend.reduce((s, d) => s + Number(d.stock_out), 0);

  const criticalCount = supply_low_stock.filter(s => {
    const avail = Number(s.available_stock);
    return avail <= 0;
  }).length;

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
                {criticalCount} material{criticalCount > 1 ? 's' : ''} out of stock
                <Link href="/inventory/supplies" className="ml-1 underline underline-offset-2 hover:text-red-900">View →</Link>
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
              {formatCurrency(supply_stock_value)} materials · {stats.total_warehouses} warehouses
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
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
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
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
            sub={`${formatCurrency(supply_stock_value)} materials`}
          />
        </div>

        {/* Alert pills row */}
        <div className="flex flex-wrap gap-2">
          {stats.supply_low_stock > 0 && (
            <AlertPill href="/inventory/supplies" tone="amber"
              icon={<Box className="h-3.5 w-3.5" />}
              label="Low Stock Materials" value={stats.supply_low_stock} />
          )}
          {stats.non_moving_supplies > 0 && (
            <AlertPill href="/inventory/non-moving?type=supplies" tone="red"
              icon={<Box className="h-3.5 w-3.5" />}
              label="Non-Moving Materials" value={stats.non_moving_supplies} />
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

        {/* Charts row — Supply trend | Status donut */}
        <div className="grid gap-4 lg:grid-cols-3">
          {/* Supply Movement Trend */}
          <Card className="lg:col-span-2">
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
                <ResponsiveContainer width="100%" height={220}>
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
                        label={({ name, value }: { name: string; value: number }) => value > 0 ? `${name}: ${value}` : ''}
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
        <div className="grid gap-4 lg:grid-cols-2">
          {/* Top Material Movers */}
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
              <Link href="/inventory/supplies" className="flex items-center gap-0.5 text-xs text-blue-600 hover:underline">All <ArrowRight className="h-3 w-3" /></Link>
            </CardHeader>
            <CardContent className="p-0">
              {recent_supply_movements.length === 0 ? (
                <div className="py-6 text-center text-sm text-muted-foreground">No recent movements.</div>
              ) : (
                <ul className="divide-y divide-border max-h-64 overflow-y-auto">
                  {recent_supply_movements
                    .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
                    .slice(0, 10)
                    .map(m => (
                      <li key={m.id} className="flex items-start gap-3 px-4 py-2.5">
                        <MovementDot type={m.type} />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-xs font-medium">{m.supply?.name ?? 'Unknown'}</p>
                          <div className="mt-0.5 flex items-center gap-1.5">
                            <MovementTypePill type={m.type} />
                            <span className={`text-xs font-semibold ${m.quantity < 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                              {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                            </span>
                          </div>
                        </div>
                        <span className="shrink-0 text-[10px] text-muted-foreground whitespace-nowrap">{formatDate(m.created_at)}</span>
                      </li>
                    ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Low stock materials */}
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
                  <TableHead className="text-right">Materials</TableHead>
                  <TableHead className="text-right">Stock Value</TableHead>
                </TableRow></TableHeader>
                <TableBody>
                  {warehouse_stock_summary.map(wh => (
                    <TableRow key={wh.id}>
                      <TableCell>
                        <div className="font-medium">{wh.name}</div>
                        <div className="font-mono text-xs text-muted-foreground">{wh.code}</div>
                      </TableCell>
                      <TableCell className="text-right font-medium tabular-nums">{Number(wh.supply_units).toLocaleString()}</TableCell>
                      <TableCell className="text-right font-bold tabular-nums text-emerald-800">{formatCurrency(Number(wh.supply_value))}</TableCell>
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
          <Link href="/inventory/supplies"><Button variant="outline" size="sm"><ArrowUpCircle className="mr-1 h-3 w-3" />View Materials</Button></Link>
          <Link href="/inventory/non-moving"><Button variant="outline" size="sm"><AlertTriangle className="mr-1 h-3 w-3" />Non-Moving</Button></Link>
          <Link href="/inventory/dead-stock"><Button variant="outline" size="sm"><Skull className="mr-1 h-3 w-3" />Dead Stock</Button></Link>
        </div>
      </div>
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
