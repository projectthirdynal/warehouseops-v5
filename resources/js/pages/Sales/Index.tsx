import { Head, router } from '@inertiajs/react';
import { useState, useCallback } from 'react';
import {
  TrendingUp,
  Users,
  Target,
  Award,
  Search,
  ChevronUp,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Filter,
} from 'lucide-react';
import {
  LineChart,
  Line,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import type {
  SaleStats,
  DailyPoint,
  AgentBar,
  FunnelStage,
  SaleLead,
  SalesFilters,
  PaginatedResponse,
} from '@/types';

interface Props {
  stats: SaleStats;
  dailyTrend: DailyPoint[];
  agentSales: AgentBar[];
  funnelData: FunnelStage[];
  salesLeads: PaginatedResponse<SaleLead>;
  agents: { id: number; name: string }[];
  filters: SalesFilters;
}

const SALES_STATUS_COLORS: Record<string, string> = {
  'New': 'bg-gray-100 text-gray-700',
  'Contacted': 'bg-blue-100 text-blue-700',
  'Agent Confirmed': 'bg-indigo-100 text-indigo-700',
  'QA Pending': 'bg-yellow-100 text-yellow-700',
  'QA Approved': 'bg-green-100 text-green-700',
  'QA Rejected': 'bg-red-100 text-red-700',
  'Ops Approved': 'bg-emerald-100 text-emerald-700',
  'Waybill Created': 'bg-purple-100 text-purple-700',
  'Cancelled': 'bg-gray-100 text-gray-500',
};

function StatCard({
  title, value, sub, icon: Icon, color,
}: {
  title: string;
  value: string | number;
  sub?: string;
  icon: React.ComponentType<{ className?: string }>;
  color: string;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
        <div className={`rounded-lg p-2 ${color}`}>
          <Icon className="h-4 w-4" />
        </div>
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{value}</div>
        {sub && <p className="text-xs text-muted-foreground mt-1">{sub}</p>}
      </CardContent>
    </Card>
  );
}

export default function SalesIndex({
  stats, dailyTrend, agentSales, funnelData, salesLeads, agents, filters,
}: Props) {
  const [search, setSearch] = useState(filters.search ?? '');

  const applyFilters = useCallback((overrides: Partial<SalesFilters>) => {
    router.get('/sales', { ...filters, ...overrides }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  }, [filters]);

  const handleDateChange = (field: 'from' | 'to', value: string) => {
    applyFilters({ [field]: value, page: undefined } as Partial<SalesFilters>);
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    applyFilters({ search, page: undefined } as Partial<SalesFilters>);
  };

  const handleSort = (col: string) => {
    const newDir = filters.sort === col && filters.dir === 'desc' ? 'asc' : 'desc';
    applyFilters({ sort: col, dir: newDir });
  };

  const handleAgentFilter = (agentId: string) => {
    applyFilters({ agent: agentId, page: undefined } as Partial<SalesFilters>);
  };

  const SortIcon = ({ col }: { col: string }) => {
    if (filters.sort !== col) return <ChevronUp className="h-3 w-3 opacity-30" />;
    return filters.dir === 'asc'
      ? <ChevronUp className="h-3 w-3 text-primary" />
      : <ChevronDown className="h-3 w-3 text-primary" />;
  };

  const funnelMax = Math.max(...funnelData.map((s) => s.count), 1);

  return (
    <AppLayout>
      <Head title="Sales Tracking" />

      <div className="space-y-6">
        {/* Header + Date Range */}
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Sales Tracking</h1>
            <p className="text-muted-foreground">Revenue pipeline and agent performance</p>
          </div>

          <div className="flex flex-wrap items-end gap-3">
            <div className="flex items-center gap-2">
              <div className="space-y-1">
                <label className="text-xs font-medium text-muted-foreground">From</label>
                <input
                  type="date"
                  value={filters.from}
                  max={filters.to}
                  onChange={(e) => handleDateChange('from', e.target.value)}
                  className="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </div>
              <span className="text-muted-foreground mt-5">–</span>
              <div className="space-y-1">
                <label className="text-xs font-medium text-muted-foreground">To</label>
                <input
                  type="date"
                  value={filters.to}
                  min={filters.from}
                  max={new Date().toISOString().split('T')[0]}
                  onChange={(e) => handleDateChange('to', e.target.value)}
                  className="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </div>
            </div>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Total Sales"
            value={stats.total_sales.toLocaleString()}
            sub="In selected period"
            icon={TrendingUp}
            color="bg-green-500/10 text-green-600"
          />
          <StatCard
            title="Conversion Rate"
            value={`${stats.conversion_rate}%`}
            sub="Leads → Sales"
            icon={Target}
            color="bg-blue-500/10 text-blue-600"
          />
          <StatCard
            title="Avg / Day"
            value={stats.avg_per_day}
            sub="Sales per day"
            icon={TrendingUp}
            color="bg-purple-500/10 text-purple-600"
          />
          <StatCard
            title="Top Agent"
            value={stats.top_agent}
            sub="Most sales in period"
            icon={Award}
            color="bg-yellow-500/10 text-yellow-600"
          />
        </div>

        {/* Daily Trend Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Daily Sales Trend</CardTitle>
            <CardDescription>Number of sales closed per day</CardDescription>
          </CardHeader>
          <CardContent>
            {dailyTrend.length === 0 ? (
              <div className="h-[280px] flex items-center justify-center text-sm text-muted-foreground">
                No sales in the selected period
              </div>
            ) : (
              <ResponsiveContainer width="100%" height={280}>
                <LineChart data={dailyTrend} margin={{ top: 4, right: 16, left: 0, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
                  <XAxis
                    dataKey="date"
                    tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                    tickLine={false}
                    axisLine={false}
                  />
                  <YAxis
                    allowDecimals={false}
                    tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                    tickLine={false}
                    axisLine={false}
                    width={30}
                  />
                  <Tooltip
                    contentStyle={{
                      backgroundColor: 'hsl(var(--card))',
                      border: '1px solid hsl(var(--border))',
                      borderRadius: '8px',
                      fontSize: 12,
                    }}
                    labelStyle={{ fontWeight: 600 }}
                  />
                  <Line
                    type="monotone"
                    dataKey="count"
                    name="Sales"
                    stroke="hsl(var(--primary))"
                    strokeWidth={2}
                    dot={{ r: 3, fill: 'hsl(var(--primary))' }}
                    activeDot={{ r: 5 }}
                  />
                </LineChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>

        {/* Agent Bar + Funnel */}
        <div className="grid gap-6 lg:grid-cols-2">
          {/* Sales by Agent */}
          <Card>
            <CardHeader>
              <CardTitle>Sales by Agent</CardTitle>
              <CardDescription>Top 10 agents in selected period</CardDescription>
            </CardHeader>
            <CardContent>
              {agentSales.length === 0 ? (
                <div className="h-[260px] flex items-center justify-center text-sm text-muted-foreground">
                  No agent data available
                </div>
              ) : (
                <ResponsiveContainer width="100%" height={260}>
                  <BarChart
                    data={agentSales}
                    layout="vertical"
                    margin={{ top: 0, right: 16, left: 8, bottom: 0 }}
                  >
                    <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="hsl(var(--border))" />
                    <XAxis
                      type="number"
                      allowDecimals={false}
                      tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                      tickLine={false}
                      axisLine={false}
                    />
                    <YAxis
                      type="category"
                      dataKey="agent_name"
                      width={90}
                      tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                      tickLine={false}
                      axisLine={false}
                    />
                    <Tooltip
                      contentStyle={{
                        backgroundColor: 'hsl(var(--card))',
                        border: '1px solid hsl(var(--border))',
                        borderRadius: '8px',
                        fontSize: 12,
                      }}
                    />
                    <Bar dataKey="count" name="Sales" fill="hsl(var(--primary))" radius={[0, 4, 4, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </CardContent>
          </Card>

          {/* Sales Funnel */}
          <Card>
            <CardHeader>
              <CardTitle>Sales Pipeline</CardTitle>
              <CardDescription>Distribution across funnel stages</CardDescription>
            </CardHeader>
            <CardContent>
              {funnelData.every((s) => s.count === 0) ? (
                <div className="h-[260px] flex items-center justify-center text-sm text-muted-foreground">
                  No pipeline data in selected period
                </div>
              ) : (
                <div className="space-y-3 pt-1">
                  {funnelData.map((stage) => (
                    <div key={stage.stage} className="space-y-1">
                      <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">{stage.stage}</span>
                        <span className="font-medium tabular-nums">{stage.count.toLocaleString()}</span>
                      </div>
                      <div className="h-2 w-full rounded-full bg-muted overflow-hidden">
                        <div
                          className="h-full rounded-full bg-primary transition-all duration-500"
                          style={{ width: `${Math.round((stage.count / funnelMax) * 100)}%` }}
                        />
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Sales Table */}
        <Card>
          <CardHeader>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <CardTitle>Sales Records</CardTitle>
                <CardDescription>
                  {salesLeads.total.toLocaleString()} sales found
                </CardDescription>
              </div>

              <div className="flex flex-wrap gap-2">
                {/* Agent filter */}
                <div className="flex items-center gap-1.5">
                  <Filter className="h-4 w-4 text-muted-foreground shrink-0" />
                  <select
                    value={filters.agent}
                    onChange={(e) => handleAgentFilter(e.target.value)}
                    className="h-9 rounded-md border border-input bg-background px-2 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <option value="">All Agents</option>
                    {agents.map((a) => (
                      <option key={a.id} value={String(a.id)}>{a.name}</option>
                    ))}
                  </select>
                </div>

                {/* Search */}
                <form onSubmit={handleSearch} className="flex gap-1.5">
                  <div className="relative">
                    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
                    <input
                      type="text"
                      placeholder="Name, phone, product…"
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      className="h-9 w-48 rounded-md border border-input bg-background pl-8 pr-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    />
                  </div>
                  <Button type="submit" size="sm" variant="outline">Search</Button>
                  {(filters.search || filters.agent) && (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      onClick={() => {
                        setSearch('');
                        applyFilters({ search: '', agent: '', page: undefined } as Partial<SalesFilters>);
                      }}
                    >
                      Clear
                    </Button>
                  )}
                </form>
              </div>
            </div>
          </CardHeader>

          <CardContent className="p-0">
            {salesLeads.data.length === 0 ? (
              <div className="text-center py-16 text-sm text-muted-foreground">
                <Users className="mx-auto h-10 w-10 mb-3 opacity-30" />
                No sales records match the current filters
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b bg-muted/40">
                        {[
                          { key: 'name',        label: 'Customer' },
                          { key: null,          label: 'Phone' },
                          { key: 'product_name', label: 'Product' },
                          { key: 'amount',      label: 'Amount' },
                          { key: 'sales_status', label: 'Stage' },
                          { key: null,          label: 'Agent' },
                          { key: 'updated_at',  label: 'Date' },
                        ].map(({ key, label }) => (
                          <th
                            key={label}
                            className={`px-4 py-3 text-left font-medium text-muted-foreground whitespace-nowrap ${key ? 'cursor-pointer select-none hover:text-foreground' : ''}`}
                            onClick={() => key && handleSort(key)}
                          >
                            <span className="inline-flex items-center gap-1">
                              {label}
                              {key && <SortIcon col={key} />}
                            </span>
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {salesLeads.data.map((lead) => (
                        <tr key={lead.id} className="hover:bg-muted/30 transition-colors">
                          <td className="px-4 py-3 font-medium">{lead.name}</td>
                          <td className="px-4 py-3 text-muted-foreground tabular-nums">{lead.phone}</td>
                          <td className="px-4 py-3 text-muted-foreground">
                            {lead.product_name ?? <span className="italic opacity-50">—</span>}
                          </td>
                          <td className="px-4 py-3 tabular-nums">
                            {lead.amount
                              ? `₱${parseFloat(lead.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                              : <span className="italic opacity-50">—</span>}
                          </td>
                          <td className="px-4 py-3">
                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${SALES_STATUS_COLORS[lead.sales_status] ?? 'bg-gray-100 text-gray-700'}`}>
                              {lead.sales_status}
                            </span>
                          </td>
                          <td className="px-4 py-3 text-muted-foreground">
                            {lead.agent_name ?? <span className="italic opacity-50">Unassigned</span>}
                          </td>
                          <td className="px-4 py-3 text-muted-foreground tabular-nums whitespace-nowrap">
                            {new Date(lead.updated_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {/* Pagination */}
                {salesLeads.last_page > 1 && (
                  <div className="flex items-center justify-between px-4 py-3 border-t">
                    <p className="text-sm text-muted-foreground">
                      {salesLeads.from}–{salesLeads.to} of {salesLeads.total.toLocaleString()}
                    </p>
                    <div className="flex gap-1">
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={salesLeads.current_page <= 1}
                        onClick={() => applyFilters({ page: salesLeads.current_page - 1 } as Partial<SalesFilters>)}
                      >
                        <ChevronLeft className="h-4 w-4" />
                      </Button>
                      <span className="flex items-center px-3 text-sm">
                        {salesLeads.current_page} / {salesLeads.last_page}
                      </span>
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={salesLeads.current_page >= salesLeads.last_page}
                        onClick={() => applyFilters({ page: salesLeads.current_page + 1 } as Partial<SalesFilters>)}
                      >
                        <ChevronRight className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                )}
              </>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
