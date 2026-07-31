import { useEffect, useState, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from 'recharts';
import {
  Truck,
  PackageCheck,
  RotateCcw,
  Clock,
  TrendingUp,
  Wallet,
  Download,
  Loader2,
  Target,
} from 'lucide-react';
import axios from 'axios';

interface Overview {
  total: number;
  delivered: number;
  returned: number;
  failed: number;
  in_transit: number;
  cancelled: number;
  on_time: number;
  on_time_rate: number;
  delivery_rate: number;
  return_rate: number;
  failure_rate: number;
  avg_transit_hrs: number | null;
  avg_transit_days: number | null;
  cod_collected: number;
  cod_at_risk: number;
  shipping_cost: number;
}

interface CourierRow {
  courier_code: string;
  courier_name: string;
  total: number;
  delivered: number;
  returned: number;
  failed: number;
  in_transit: number;
  cancelled: number;
  delivery_rate: number;
  return_rate: number;
  failure_rate: number;
  on_time_rate: number;
  avg_transit_hrs: number | null;
  avg_transit_days: number | null;
  cod_collected: number;
  cod_at_risk: number;
  shipping_cost: number;
}

interface TrendPoint {
  period: string;
  total: number;
  delivered: number;
  returned: number;
  failed: number;
  delivery_rate: number;
  return_rate: number;
}

interface StatusItem {
  status: string;
  count: number;
}

interface TransitBucket {
  bucket: string;
  count: number;
}

interface CityRow {
  city: string;
  total: number;
  delivered: number;
  returned: number;
  delivery_rate: number;
  return_rate: number;
}

interface Courier {
  code: string;
  name: string;
}

interface Props {
  overview: Overview;
  by_courier: CourierRow[];
  trends: { group_by: string; data: TrendPoint[] };
  status_dist: StatusItem[];
  transit: TransitBucket[];
  top_cities: CityRow[];
  couriers: Courier[];
  filters: { from: string; to: string; courier: string | null };
}

function formatPeso(amount: number): string {
  return (
    '₱' +
    Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  );
}

const STATUS_COLORS: Record<string, string> = {
  DELIVERED: '#22c55e',
  RETURNED: '#ef4444',
  DELIVERY_FAILED: '#f97316',
  IN_TRANSIT: '#3b82f6',
  DISPATCHED: '#06b6d4',
  PICKED_UP: '#0ea5e9',
  ARRIVED_HUB: '#14b8a6',
  OUT_FOR_DELIVERY: '#f59e0b',
  RETURNING: '#f97316',
  CANCELLED: '#94a3b8',
  PENDING: '#eab308',
};

const PIE_COLORS = [
  '#22c55e',
  '#ef4444',
  '#f97316',
  '#3b82f6',
  '#06b6d4',
  '#94a3b8',
  '#eab308',
  '#14b8a6',
  '#0ea5e9',
  '#f59e0b',
];

export default function CourierAnalytics({
  overview,
  by_courier,
  trends,
  status_dist,
  transit,
  top_cities,
  couriers,
  filters,
}: Props) {
  const [from, setFrom] = useState(filters.from);
  const [to, setTo] = useState(filters.to);
  const [courier, setCourier] = useState(filters.courier ?? 'all');
  const [data, setData] = useState({
    overview,
    by_courier,
    trends,
    status_dist,
    transit,
    top_cities,
  });
  const [loading, setLoading] = useState(false);

  const refresh = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams();
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    if (courier && courier !== 'all') params.set('courier', courier);

    axios
      .get(`/waybills/courier-analytics/api?${params.toString()}`)
      .then(({ data: d }) => {
        setData({
          overview: d.overview,
          by_courier: d.by_courier,
          trends: d.trends,
          status_dist: d.status_dist,
          transit: d.transit,
          top_cities: d.top_cities,
        });
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [from, to, courier]);

  useEffect(() => {
    const t = setTimeout(refresh, 400);
    return () => clearTimeout(t);
  }, [refresh]);

  function handleExport() {
    const params = new URLSearchParams();
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    window.location.href = `/waybills/courier-analytics/export?${params.toString()}`;
  }

  const ov = data.overview;

  return (
    <AppLayout>
      <Head title="Courier Analytics" />

      <div className="space-y-4 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold font-display">Courier Analytics</h1>
            <p className="text-sm text-muted-foreground">
              On-time rate, average transit, return rate per courier
            </p>
          </div>
          <Button variant="outline" onClick={handleExport} disabled={loading}>
            <Download className="mr-2 h-4 w-4" />
            Export CSV
          </Button>
        </div>

        {/* Filters */}
        <div className="flex items-center gap-2 flex-wrap">
          <Input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="w-40"
          />
          <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-40" />
          <Select value={courier} onValueChange={setCourier}>
            <SelectTrigger className="w-40">
              <SelectValue placeholder="All Couriers" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Couriers</SelectItem>
              {couriers.map((c) => (
                <SelectItem key={c.code} value={c.code}>
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {loading && <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />}
        </div>

        {/* Stats Row */}
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
          <StatCard
            icon={<Truck className="h-4 w-4 text-blue-500" />}
            label="Total Shipments"
            value={String(ov.total)}
          />
          <StatCard
            icon={<PackageCheck className="h-4 w-4 text-green-500" />}
            label="Delivery Rate"
            value={`${ov.delivery_rate}%`}
            sub={`${ov.delivered} delivered`}
          />
          <StatCard
            icon={<Target className="h-4 w-4 text-emerald-500" />}
            label="On-Time Rate"
            value={`${ov.on_time_rate}%`}
            sub={`${ov.on_time} of ${ov.delivered}`}
          />
          <StatCard
            icon={<RotateCcw className="h-4 w-4 text-red-500" />}
            label="Return Rate"
            value={`${ov.return_rate}%`}
            sub={`${ov.returned} returned`}
          />
          <StatCard
            icon={<Clock className="h-4 w-4 text-cyan-500" />}
            label="Avg Transit"
            value={ov.avg_transit_days !== null ? `${ov.avg_transit_days}d` : 'N/A'}
            sub={ov.avg_transit_hrs !== null ? `${ov.avg_transit_hrs}h` : ''}
          />
          <StatCard
            icon={<Wallet className="h-4 w-4 text-amber-500" />}
            label="COD Collected"
            value={formatPeso(ov.cod_collected)}
            sub={`Risk: ${formatPeso(ov.cod_at_risk)}`}
          />
        </div>

        {/* Charts Row 1 */}
        <div className="grid gap-4 lg:grid-cols-2">
          {/* Trend Chart */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-2 text-sm font-medium">
                <TrendingUp className="h-4 w-4" />
                Shipment Trends ({trends.group_by})
              </CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={250}>
                <LineChart
                  data={data.trends.data}
                  margin={{ top: 5, right: 10, bottom: 0, left: -15 }}
                >
                  <CartesianGrid
                    strokeDasharray="3 3"
                    vertical={false}
                    stroke="hsl(var(--chart-grid))"
                  />
                  <XAxis
                    dataKey="period"
                    tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                    axisLine={false}
                    tickLine={false}
                  />
                  <YAxis
                    tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                    axisLine={false}
                    tickLine={false}
                  />
                  <Tooltip
                    contentStyle={{
                      borderRadius: 12,
                      border: '1px solid hsl(var(--border))',
                      background: 'hsl(var(--card))',
                      fontSize: 12,
                    }}
                  />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Line
                    type="monotone"
                    dataKey="total"
                    stroke="#3b82f6"
                    strokeWidth={2}
                    name="Total"
                  />
                  <Line
                    type="monotone"
                    dataKey="delivered"
                    stroke="#22c55e"
                    strokeWidth={2}
                    name="Delivered"
                  />
                  <Line
                    type="monotone"
                    dataKey="returned"
                    stroke="#ef4444"
                    strokeWidth={2}
                    name="Returned"
                  />
                </LineChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          {/* Status Distribution Pie */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Status Distribution</CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={250}>
                <PieChart>
                  <Pie
                    data={data.status_dist}
                    dataKey="count"
                    nameKey="status"
                    cx="50%"
                    cy="50%"
                    outerRadius={90}
                    label={(e: any) => `${e.status}: ${e.count}`}
                    labelLine={false}
                  >
                    {data.status_dist.map((entry, idx) => (
                      <Cell
                        key={entry.status}
                        fill={STATUS_COLORS[entry.status] ?? PIE_COLORS[idx % PIE_COLORS.length]}
                      />
                    ))}
                  </Pie>
                  <Tooltip
                    contentStyle={{
                      borderRadius: 12,
                      border: '1px solid hsl(var(--border))',
                      background: 'hsl(var(--card))',
                      fontSize: 12,
                    }}
                  />
                </PieChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        </div>

        {/* Charts Row 2 */}
        <div className="grid gap-4 lg:grid-cols-2">
          {/* Transit Time Distribution */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">
                Transit Time Distribution (Delivered)
              </CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={220}>
                <BarChart data={data.transit} margin={{ top: 5, right: 10, bottom: 0, left: -15 }}>
                  <CartesianGrid
                    strokeDasharray="3 3"
                    vertical={false}
                    stroke="hsl(var(--chart-grid))"
                  />
                  <XAxis
                    dataKey="bucket"
                    tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                    axisLine={false}
                    tickLine={false}
                  />
                  <YAxis
                    tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                    axisLine={false}
                    tickLine={false}
                  />
                  <Tooltip
                    contentStyle={{
                      borderRadius: 12,
                      border: '1px solid hsl(var(--border))',
                      background: 'hsl(var(--card))',
                      fontSize: 12,
                    }}
                  />
                  <Bar dataKey="count" fill="#3b82f6" radius={[6, 6, 0, 0]} name="Shipments" />
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          {/* Delivery vs Return Rate Trend */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Delivery vs Return Rate (%)</CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={220}>
                <LineChart
                  data={data.trends.data}
                  margin={{ top: 5, right: 10, bottom: 0, left: -15 }}
                >
                  <CartesianGrid
                    strokeDasharray="3 3"
                    vertical={false}
                    stroke="hsl(var(--chart-grid))"
                  />
                  <XAxis
                    dataKey="period"
                    tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                    axisLine={false}
                    tickLine={false}
                  />
                  <YAxis
                    tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                    axisLine={false}
                    tickLine={false}
                    domain={[0, 100]}
                  />
                  <Tooltip
                    contentStyle={{
                      borderRadius: 12,
                      border: '1px solid hsl(var(--border))',
                      background: 'hsl(var(--card))',
                      fontSize: 12,
                    }}
                  />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Line
                    type="monotone"
                    dataKey="delivery_rate"
                    stroke="#22c55e"
                    strokeWidth={2}
                    name="Delivery Rate %"
                  />
                  <Line
                    type="monotone"
                    dataKey="return_rate"
                    stroke="#ef4444"
                    strokeWidth={2}
                    name="Return Rate %"
                  />
                </LineChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        </div>

        {/* Per-Courier Table */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">Per-Courier Breakdown</CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Courier</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead className="text-right">Delivered</TableHead>
                  <TableHead className="text-right">Returned</TableHead>
                  <TableHead className="text-right">Failed</TableHead>
                  <TableHead className="text-right">Delivery %</TableHead>
                  <TableHead className="text-right">On-Time %</TableHead>
                  <TableHead className="text-right">Return %</TableHead>
                  <TableHead className="text-right">Avg Transit</TableHead>
                  <TableHead className="text-right">COD Collected</TableHead>
                  <TableHead className="text-right">COD at Risk</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.by_courier.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={11} className="text-center text-muted-foreground py-8">
                      No courier data for this period.
                    </TableCell>
                  </TableRow>
                ) : (
                  data.by_courier.map((row) => (
                    <TableRow key={row.courier_code}>
                      <TableCell className="font-medium">{row.courier_name}</TableCell>
                      <TableCell className="text-right">{row.total}</TableCell>
                      <TableCell className="text-right text-green-600">{row.delivered}</TableCell>
                      <TableCell className="text-right text-red-600">{row.returned}</TableCell>
                      <TableCell className="text-right text-orange-600">{row.failed}</TableCell>
                      <TableCell className="text-right">
                        <RateBadge value={row.delivery_rate} good={70} />
                      </TableCell>
                      <TableCell className="text-right">
                        <RateBadge value={row.on_time_rate} good={80} />
                      </TableCell>
                      <TableCell className="text-right">
                        <RateBadge value={row.return_rate} good={999} warn={5} inverted />
                      </TableCell>
                      <TableCell className="text-right text-sm">
                        {row.avg_transit_days !== null ? `${row.avg_transit_days}d` : 'N/A'}
                      </TableCell>
                      <TableCell className="text-right text-sm font-medium">
                        {formatPeso(row.cod_collected)}
                      </TableCell>
                      <TableCell className="text-right text-sm text-red-600">
                        {row.cod_at_risk > 0 ? formatPeso(row.cod_at_risk) : '—'}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* Top Cities Table */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">Top Cities by Shipment Volume</CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>City</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead className="text-right">Delivered</TableHead>
                  <TableHead className="text-right">Returned</TableHead>
                  <TableHead className="text-right">Delivery %</TableHead>
                  <TableHead className="text-right">Return %</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.top_cities.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                      No city data available.
                    </TableCell>
                  </TableRow>
                ) : (
                  data.top_cities.map((row) => (
                    <TableRow key={row.city}>
                      <TableCell className="font-medium">{row.city}</TableCell>
                      <TableCell className="text-right">{row.total}</TableCell>
                      <TableCell className="text-right text-green-600">{row.delivered}</TableCell>
                      <TableCell className="text-right text-red-600">{row.returned}</TableCell>
                      <TableCell className="text-right">
                        <RateBadge value={row.delivery_rate} good={70} />
                      </TableCell>
                      <TableCell className="text-right">
                        <RateBadge value={row.return_rate} good={999} warn={5} inverted />
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}

function StatCard({
  icon,
  label,
  value,
  sub,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  sub?: string;
}) {
  return (
    <Card className="p-3">
      <div className="flex items-center gap-2 mb-1">
        {icon}
        <span className="text-xs text-muted-foreground">{label}</span>
      </div>
      <div className="text-lg font-bold font-display leading-tight">{value}</div>
      {sub && <div className="text-xs text-muted-foreground/70">{sub}</div>}
    </Card>
  );
}

function RateBadge({
  value,
  good,
  warn,
  inverted,
}: {
  value: number;
  good: number;
  warn?: number;
  inverted?: boolean;
}) {
  const isGood = inverted ? value < (warn ?? 5) : value >= good;
  const isWarn = !isGood && (inverted ? value < good : value >= (warn ?? 50));

  return (
    <Badge
      variant="secondary"
      className={
        isGood
          ? 'text-xs bg-green-100 text-green-700'
          : isWarn
            ? 'text-xs bg-amber-100 text-amber-700'
            : 'text-xs bg-red-100 text-red-700'
      }
    >
      {value}%
    </Badge>
  );
}
