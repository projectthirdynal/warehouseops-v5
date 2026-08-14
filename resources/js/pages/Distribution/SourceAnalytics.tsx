import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  TrendingUp,
  TrendingDown,
  DollarSign,
  Target,
  BarChart3,
  Award,
  Activity,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { KpiCard } from '@/components/KpiCard';
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
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Cell,
  PieChart,
  Pie,
  Legend,
} from 'recharts';

interface SourceRow {
  source: string;
  label: string;
  total_leads: number;
  assigned: number;
  converted: number;
  conversion_rate: number;
  total_revenue: number;
  total_cost: number;
  cpa: number;
  roi: number;
  avg_handle_time_hrs: number;
  avg_order_value: number;
}

interface Summary {
  total_leads: number;
  total_converted: number;
  overall_conversion_rate: number;
  total_revenue: number;
  total_cost: number;
  blended_cpa: number;
  blended_roi: number;
}

interface TrendPoint {
  date: string;
  source: string;
  leads: number;
  conversions: number;
}

interface TopSource {
  source: string;
  label: string;
  conversion_rate: number;
  revenue: number;
}

interface Props {
  sources: SourceRow[];
  summary: Summary;
  trend: TrendPoint[];
  top_sources: TopSource[];
  days: number;
}

const COLORS = [
  '#3b82f6',
  '#10b981',
  '#f59e0b',
  '#ef4444',
  '#8b5cf6',
  '#ec4899',
  '#14b8a6',
  '#f97316',
  '#6366f1',
  '#84cc16',
  '#06b6d4',
];

const fmtCurrency = (v: number) =>
  new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(v);
const fmtPct = (v: number) => `${v.toFixed(1)}%`;
const fmtNum = (v: number) => new Intl.NumberFormat('en-PH').format(v);

export default function SourceAnalytics({ sources, summary, trend, top_sources, days }: Props) {
  const [period, setPeriod] = useState(days);

  const handlePeriodChange = (newDays: number) => {
    setPeriod(newDays);
    router.get('/distribution/source-analytics', { days: newDays }, { preserveScroll: true });
  };

  const chartData = sources.map((s) => ({
    name: s.label,
    leads: s.total_leads,
    conversions: s.converted,
    revenue: s.total_revenue,
  }));
  const roiData = sources.map((s) => ({ name: s.label, roi: s.roi, cpa: s.cpa }));
  const pieData = sources
    .filter((s) => s.total_leads > 0)
    .map((s, i) => ({ name: s.label, value: s.total_leads, fill: COLORS[i % COLORS.length] }));

  // Aggregate trend by source for stacked bar
  const trendBySource = trend.reduce<Record<string, Record<string, number>>>((acc, t) => {
    if (!acc[t.source]) acc[t.source] = {};
    acc[t.source][t.date] = (acc[t.source][t.date] || 0) + t.leads;
    return acc;
  }, {});
  const trendDates = [...new Set(trend.map((t) => t.date))].sort();
  const stackedData = trendDates.map((date) => {
    const row: Record<string, string | number> = { date };
    Object.keys(trendBySource).forEach((src) => {
      row[src] = trendBySource[src][date] || 0;
    });
    return row;
  });

  return (
    <AppLayout>
      <Head title="Source Analytics" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Source Analytics</h1>
            <p className="text-sm text-muted-foreground">
              Conversion rate, CPA, and ROI per lead source
            </p>
          </div>
          <div className="flex gap-2">
            {[7, 14, 30, 90].map((d) => (
              <button
                key={d}
                onClick={() => handlePeriodChange(d)}
                className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${
                  period === d ? 'bg-primary text-primary-foreground' : 'bg-muted hover:bg-muted/80'
                }`}
              >
                {d}d
              </button>
            ))}
          </div>
        </div>

        {/* Summary KPIs */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <KpiCard
            title="Total Leads"
            value={fmtNum(summary.total_leads)}
            subtitle={`${fmtNum(summary.total_converted)} converted`}
            icon={<Target className="h-5 w-5" />}
            variant="primary"
          />
          <KpiCard
            title="Conversion Rate"
            value={fmtPct(summary.overall_conversion_rate)}
            icon={<Activity className="h-5 w-5" />}
            variant={summary.overall_conversion_rate >= 15 ? 'success' : 'warning'}
          />
          <KpiCard
            title="Blended CPA"
            value={fmtCurrency(summary.blended_cpa)}
            subtitle={`Total cost: ${fmtCurrency(summary.total_cost)}`}
            icon={<DollarSign className="h-5 w-5" />}
            variant="default"
          />
          <KpiCard
            title="Blended ROI"
            value={fmtPct(summary.blended_roi)}
            subtitle={`Revenue: ${fmtCurrency(summary.total_revenue)}`}
            icon={
              summary.blended_roi >= 0 ? (
                <TrendingUp className="h-5 w-5" />
              ) : (
                <TrendingDown className="h-5 w-5" />
              )
            }
            variant={summary.blended_roi >= 0 ? 'success' : 'destructive'}
          />
        </div>

        {/* Charts */}
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <BarChart3 className="h-5 w-5" /> Leads & Conversions by Source
              </CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis
                    dataKey="name"
                    tick={{ fontSize: 11 }}
                    angle={-20}
                    textAnchor="end"
                    height={60}
                  />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Bar dataKey="leads" fill="#3b82f6" radius={[4, 4, 0, 0]} />
                  <Bar dataKey="conversions" fill="#10b981" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Award className="h-5 w-5" /> Lead Distribution
              </CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={300}>
                <PieChart>
                  <Pie
                    data={pieData}
                    dataKey="value"
                    nameKey="name"
                    cx="50%"
                    cy="50%"
                    outerRadius={100}
                    label={(e) => e.name}
                  >
                    {pieData.map((entry, i) => (
                      <Cell key={i} fill={entry.fill} />
                    ))}
                  </Pie>
                  <Tooltip />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                </PieChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>CPA & ROI by Source</CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart data={roiData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis
                    dataKey="name"
                    tick={{ fontSize: 11 }}
                    angle={-20}
                    textAnchor="end"
                    height={60}
                  />
                  <YAxis yAxisId="left" tick={{ fontSize: 11 }} />
                  <YAxis yAxisId="right" orientation="right" tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Bar yAxisId="left" dataKey="cpa" fill="#f59e0b" radius={[4, 4, 0, 0]} />
                  <Bar yAxisId="right" dataKey="roi" fill="#8b5cf6" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Lead Trend by Source</CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart data={stackedData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis dataKey="date" tick={{ fontSize: 10 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Legend wrapperStyle={{ fontSize: 10 }} />
                  {Object.keys(trendBySource).map((src, i) => (
                    <Bar key={src} dataKey={src} stackId="a" fill={COLORS[i % COLORS.length]} />
                  ))}
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        </div>

        {/* Top Sources */}
        <Card>
          <CardHeader>
            <CardTitle>Top Converting Sources</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex flex-wrap gap-3">
              {top_sources.map((s, i) => (
                <div key={s.source} className="flex items-center gap-2 rounded-lg border p-3">
                  <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                    {i + 1}
                  </span>
                  <div>
                    <p className="text-sm font-medium">{s.label}</p>
                    <p className="text-xs text-muted-foreground">
                      {fmtPct(s.conversion_rate)} conv · {fmtCurrency(s.revenue)}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {/* Detailed Table */}
        <Card>
          <CardHeader>
            <CardTitle>Source Performance Breakdown</CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Source</TableHead>
                  <TableHead className="text-right">Leads</TableHead>
                  <TableHead className="text-right">Assigned</TableHead>
                  <TableHead className="text-right">Converted</TableHead>
                  <TableHead className="text-right">Conv. Rate</TableHead>
                  <TableHead className="text-right">Revenue</TableHead>
                  <TableHead className="text-right">Cost</TableHead>
                  <TableHead className="text-right">CPA</TableHead>
                  <TableHead className="text-right">ROI</TableHead>
                  <TableHead className="text-right">Avg Handle (hrs)</TableHead>
                  <TableHead className="text-right">Avg Order Value</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sources.map((s) => (
                  <TableRow key={s.source}>
                    <TableCell className="font-medium">{s.label}</TableCell>
                    <TableCell className="text-right">{fmtNum(s.total_leads)}</TableCell>
                    <TableCell className="text-right">{fmtNum(s.assigned)}</TableCell>
                    <TableCell className="text-right">{fmtNum(s.converted)}</TableCell>
                    <TableCell className="text-right">
                      <Badge
                        variant={
                          s.conversion_rate >= 15
                            ? 'default'
                            : s.conversion_rate >= 5
                              ? 'secondary'
                              : 'outline'
                        }
                      >
                        {fmtPct(s.conversion_rate)}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">{fmtCurrency(s.total_revenue)}</TableCell>
                    <TableCell className="text-right text-muted-foreground">
                      {fmtCurrency(s.total_cost)}
                    </TableCell>
                    <TableCell className="text-right">{fmtCurrency(s.cpa)}</TableCell>
                    <TableCell className="text-right">
                      <span
                        className={
                          s.roi >= 0 ? 'text-success font-medium' : 'text-destructive font-medium'
                        }
                      >
                        {fmtPct(s.roi)}
                      </span>
                    </TableCell>
                    <TableCell className="text-right text-muted-foreground">
                      {s.avg_handle_time_hrs.toFixed(1)}
                    </TableCell>
                    <TableCell className="text-right">{fmtCurrency(s.avg_order_value)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
