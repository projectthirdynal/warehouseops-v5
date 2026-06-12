import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { KpiCard } from '@/components/KpiCard';
import {
  Warehouse, AlertTriangle, ShoppingCart, FileText, TrendingUp,
  Box, SlidersHorizontal, ArrowRight, ArrowUpCircle,
  RefreshCw, Zap, Tag, MoveRight, Skull, Layers,
} from 'lucide-react';
import { formatDate, formatCurrency } from '@/lib/utils';
import type { PageProps, User } from '@/types';
import {
  AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
  PieChart, Pie, Cell, BarChart, Bar,
} from 'recharts';
import type { ColumnDef } from '@tanstack/react-table';

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
    out_of_stock: number;
    today_scans: number;
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

const lowStockColumns: ColumnDef<SupplyLowStockRow>[] = [
  {
    accessorKey: 'sku',
    header: 'SKU',
    cell: ({ row }) => <span className="font-mono text-[11px] text-slate-300">{row.original.sku}</span>,
  },
  {
    accessorKey: 'supply_name',
    header: 'Material',
    cell: ({ row }) => <span className="text-sm font-medium">{row.original.supply_name}</span>,
  },
  {
    accessorKey: 'available_stock',
    header: () => <span className="text-right w-full block">Available</span>,
    cell: ({ row }) => {
      const avail = Number(row.original.available_stock);
      const isCritical = avail <= 0;
      return (
        <span className={`text-right block font-bold tabular-nums ${isCritical ? 'text-red-400' : 'text-amber-400'}`}>
          {avail}
        </span>
      );
    },
  },
  {
    accessorKey: 'reorder_point',
    header: () => <span className="text-right w-full block">Reorder</span>,
    cell: ({ row }) => (
      <span className="text-right block font-mono text-sm text-slate-400">
        {Number(row.original.reorder_point)}
      </span>
    ),
  },
];

const warehouseColumns: ColumnDef<WarehouseStockSummary>[] = [
  {
    accessorKey: 'name',
    header: 'Warehouse',
    cell: ({ row }) => (
      <div>
        <div className="font-medium">{row.original.name}</div>
        <div className="font-mono text-xs text-muted-foreground">{row.original.code}</div>
      </div>
    ),
  },
  {
    accessorKey: 'supply_units',
    header: () => <span className="text-right w-full block">Materials</span>,
    cell: ({ row }) => (
      <span className="text-right block font-medium tabular-nums">
        {Number(row.original.supply_units).toLocaleString()}
      </span>
    ),
  },
  {
    accessorKey: 'supply_value',
    header: () => <span className="text-right w-full block">Stock Value</span>,
    cell: ({ row }) => (
      <span className="text-right block font-bold tabular-nums text-emerald-400">
        {formatCurrency(Number(row.original.supply_value))}
      </span>
    ),
  },
];

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
        <div className="border-b border-red-900/50 bg-red-950/30 px-6 py-2.5">
          <div className="flex flex-wrap items-center gap-x-6 gap-y-1">
            {criticalCount > 0 && (
              <span className="flex items-center gap-1.5 text-sm font-medium text-red-400">
                <AlertTriangle className="h-4 w-4 shrink-0" />
                {criticalCount} material{criticalCount > 1 ? 's' : ''} out of stock
                <Link href="/inventory/supplies" className="ml-1 underline underline-offset-2 hover:text-red-300">View →</Link>
              </span>
            )}
            {stats.pending_adjustments > 0 && (
              <span className="flex items-center gap-1.5 text-sm font-medium text-orange-400">
                <SlidersHorizontal className="h-4 w-4 shrink-0" />
                {stats.pending_adjustments} adjustment{stats.pending_adjustments > 1 ? 's' : ''} awaiting approval
                {canUseMaterialsAndAdjustments && (
                  <Link href="/inventory/adjustments?status=PENDING" className="ml-1 underline underline-offset-2 hover:text-orange-300">Review →</Link>
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
              {stats.total_supplies.toLocaleString()} materials · {stats.total_warehouses} warehouses
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
            title="Active Materials"
            value={stats.total_supplies.toLocaleString()}
            subtitle={`${formatCurrency(supply_stock_value)} total value`}
            icon={<Box className="h-5 w-5 text-purple-400" />}
            variant="primary"
          />
          <KpiCard
            title="Warehouses"
            value={stats.total_warehouses.toLocaleString()}
            icon={<Warehouse className="h-5 w-5 text-emerald-400" />}
            variant="success"
          />
          <KpiCard
            title="Total Stock Value"
            value={formatCurrency(stats.stock_value)}
            subtitle={`${stats.total_supplies.toLocaleString()} materials`}
            icon={<TrendingUp className="h-5 w-5 text-green-400" />}
            variant="default"
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

        {/* Operations 4-up KPI row */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div className="rounded-lg border bg-card p-4 flex flex-col gap-1">
            <span className="text-xs text-muted-foreground font-medium uppercase tracking-wide">Out of Stock</span>
            <span className={`text-2xl font-bold tabular-nums ${stats.out_of_stock > 0 ? 'text-red-400' : 'text-emerald-400'}`}>
              {stats.out_of_stock.toLocaleString()}
            </span>
            <Link href="/inventory/supplies?stock_status=OUT_OF_STOCK" className="text-[11px] text-muted-foreground hover:text-foreground mt-auto">
              View materials →
            </Link>
          </div>
          <div className="rounded-lg border bg-card p-4 flex flex-col gap-1">
            <span className="text-xs text-muted-foreground font-medium uppercase tracking-wide">Low Stock</span>
            <span className={`text-2xl font-bold tabular-nums ${stats.supply_low_stock > 0 ? 'text-amber-400' : 'text-emerald-400'}`}>
              {stats.supply_low_stock.toLocaleString()}
            </span>
            <Link href="/inventory/supplies?stock_status=NON_MOVING" className="text-[11px] text-muted-foreground hover:text-foreground mt-auto">
              View materials →
            </Link>
          </div>
          <div className="rounded-lg border bg-card p-4 flex flex-col gap-1">
            <span className="text-xs text-muted-foreground font-medium uppercase tracking-wide">Pending POs</span>
            <span className={`text-2xl font-bold tabular-nums ${stats.open_pos > 0 ? 'text-blue-400' : 'text-muted-foreground'}`}>
              {stats.open_pos.toLocaleString()}
            </span>
            <Link href="/procurement/orders" className="text-[11px] text-muted-foreground hover:text-foreground mt-auto">
              View orders →
            </Link>
          </div>
          <div className="rounded-lg border bg-card p-4 flex flex-col gap-1">
            <span className="text-xs text-muted-foreground font-medium uppercase tracking-wide">Today's Movements</span>
            <span className="text-2xl font-bold tabular-nums text-purple-400">
              {stats.today_scans.toLocaleString()}
            </span>
            <span className="text-[11px] text-muted-foreground mt-auto">
              stock movements today
            </span>
          </div>
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
                    <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
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
          {/* Top Material Movers — Bar Chart */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-1.5 text-sm font-semibold">
                <MoveRight className="h-4 w-4 text-purple-500" />
                Top Material Movers (30d)
              </CardTitle>
            </CardHeader>
            <CardContent>
              {top_supply_movers.length === 0 ? (
                <div className="flex h-48 items-center justify-center text-sm text-muted-foreground">No movement data.</div>
              ) : (
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart
                    data={top_supply_movers.map(m => ({ name: m.sku, full_name: m.name, qty: Number(m.total_qty) }))}
                    layout="vertical"
                    margin={{ top: 0, right: 12, left: 0, bottom: 0 }}
                  >
                    <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" horizontal={false} />
                    <XAxis type="number" tick={{ fontSize: 10 }} allowDecimals={false} />
                    <YAxis type="category" dataKey="name" tick={{ fontSize: 10 }} width={55} />
                    <Tooltip
                      contentStyle={{ fontSize: 12, borderRadius: 6 }}
                      formatter={(v: number) => [`${Number(v).toLocaleString()} units`, 'Qty']}
                    />
                    <Bar dataKey="qty" fill="#a78bfa" radius={[0, 4, 4, 0]} maxBarSize={18} />
                  </BarChart>
                </ResponsiveContainer>
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
                            <span className={`text-xs font-semibold ${m.quantity < 0 ? 'text-red-400' : 'text-emerald-400'}`}>
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
              <DataTable
                columns={lowStockColumns}
                data={supply_low_stock}
                maxHeight={320}
              />
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
              <DataTable
                columns={warehouseColumns}
                data={warehouse_stock_summary}
              />
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

function AlertPill({ href, icon, label, value, tone }: {
  href: string; icon: React.ReactNode; label: string; value: number;
  tone: 'orange' | 'amber' | 'blue' | 'green' | 'red' | 'yellow';
}) {
  const cls: Record<string, string> = {
    orange: 'bg-orange-950/40 text-orange-300 border-orange-800',
    amber:  'bg-amber-950/40 text-amber-300 border-amber-800',
    blue:   'bg-blue-950/40 text-blue-300 border-blue-800',
    green:  'bg-green-950/40 text-green-300 border-green-800',
    red:    'bg-red-950/40 text-red-300 border-red-800',
    yellow: 'bg-yellow-950/40 text-yellow-300 border-yellow-800',
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
    STOCK_IN:    'bg-emerald-950/40 text-emerald-300 border border-emerald-800',
    STOCK_OUT:   'bg-red-950/40 text-red-300 border border-red-800',
    ADJUSTMENT:  'bg-yellow-950/40 text-yellow-300 border border-yellow-800',
    RETURN:      'bg-blue-950/40 text-blue-300 border border-blue-800',
    RESERVATION: 'bg-purple-950/40 text-purple-300 border border-purple-800',
    RELEASE:     'bg-indigo-950/40 text-indigo-300 border border-indigo-800',
  };
  const label: Record<string, string> = {
    STOCK_IN: 'In', STOCK_OUT: 'Out', ADJUSTMENT: 'Adj',
    RETURN: 'Return', RESERVATION: 'Rsrv', RELEASE: 'Release',
  };
  return (
    <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${cls[type] ?? 'bg-slate-800 text-slate-300 border border-slate-700'}`}>
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
