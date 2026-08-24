import { Head, Link, router, usePage } from '@inertiajs/react';
import {
  BarChart3,
  CalendarDays,
  CheckCircle2,
  CircleDollarSign,
  PackageSearch,
  PhoneCall,
  ShoppingCart,
  Target,
  UsersRound,
} from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import KpiCard from '@/components/telesales/KpiCard';
import DonutBreakdown, { BreakdownItem } from '@/components/telesales/DonutBreakdown';
import TrendChart, { TrendPoint } from '@/components/telesales/TrendChart';
import SectionCard from '@/components/telesales/SectionCard';
import StatusPill from '@/components/telesales/StatusPill';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { PageProps } from '@/types';

interface KpiValue {
  value: number;
  trend: number | null;
}

interface DashboardKpis {
  available: KpiValue;
  assigned: KpiValue;
  contacted: KpiValue;
  orders: KpiValue;
  revenue: KpiValue;
  conversion: KpiValue;
}

interface PoolRequestRow {
  id: number;
  request_number: string;
  brand_name: string;
  region: string;
  requested_quantity: number;
  status: string;
  requested_by: string;
  created_at: string | null;
}

interface AgentRow {
  id: number;
  name: string;
  contact_rate: number;
  orders: number;
  conversion: number;
}

interface Props {
  range: string;
  period: {
    start: string;
    end: string;
    days: number;
  };
  kpis: DashboardKpis;
  trend: TrendPoint[];
  brandBreakdown: BreakdownItem[];
  regionBreakdown: BreakdownItem[];
  recentPoolRequests: PoolRequestRow[];
  topAgents: AgentRow[];
}

function formatCurrency(value: number): string {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 0,
  }).format(value);
}

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function formatRange(start: string, end: string): string {
  const first = new Date(`${start}T00:00:00`);
  const last = new Date(`${end}T00:00:00`);

  const a = first.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
  const b = last.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  return `${a} – ${b}`;
}

export default function TelesalesDashboard({
  range,
  period,
  kpis,
  trend,
  brandBreakdown,
  regionBreakdown,
  recentPoolRequests,
  topAgents,
}: Props) {
  const page = usePage<PageProps>();
  const firstName = page.props.auth.user.name.split(' ')[0] || page.props.auth.user.name;

  const changeRange = (value: string) => {
    router.get(
      '/telesales',
      { range: value },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  };

  return (
    <TelesalesLayout>
      <Head title="Telesales Dashboard" />

      <div className="mx-auto w-full max-w-[1680px] space-y-5 p-4 md:p-6 lg:p-8">
        <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
          <div>
            <h2 className="text-xl font-bold tracking-tight text-slate-950 md:text-2xl">
              Welcome back, {firstName}.
            </h2>
            <p className="mt-1 text-sm text-slate-500">
              Here is what is happening with telesales operations for the selected period.
            </p>
          </div>

          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div className="flex h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-600 shadow-sm">
              <CalendarDays className="h-4 w-4 text-slate-400" />
              <span>{formatRange(period.start, period.end)}</span>
            </div>
            <Select value={range} onValueChange={changeRange}>
              <SelectTrigger className="w-full bg-white sm:w-[140px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="7d">Last 7 days</SelectItem>
                <SelectItem value="30d">Last 30 days</SelectItem>
                <SelectItem value="90d">Last 90 days</SelectItem>
              </SelectContent>
            </Select>
            <Button
              onClick={() => router.visit('/reports')}
              className="bg-blue-600 hover:bg-blue-700"
            >
              <BarReportIcon className="mr-2 h-4 w-4" />
              Open Reports
            </Button>
          </div>
        </div>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
          <KpiCard
            label="Available Leads"
            value={kpis.available.value.toLocaleString()}
            trend={kpis.available.trend}
            icon={PackageSearch}
            accent="blue"
          />
          <KpiCard
            label="Assigned Leads"
            value={kpis.assigned.value.toLocaleString()}
            trend={kpis.assigned.trend}
            icon={UsersRound}
            accent="green"
          />
          <KpiCard
            label="Contacted"
            value={kpis.contacted.value.toLocaleString()}
            trend={kpis.contacted.trend}
            icon={PhoneCall}
            accent="purple"
          />
          <KpiCard
            label="Orders"
            value={kpis.orders.value.toLocaleString()}
            trend={kpis.orders.trend}
            icon={ShoppingCart}
            accent="orange"
          />
          <KpiCard
            label="Revenue"
            value={formatCurrency(kpis.revenue.value)}
            trend={kpis.revenue.trend}
            icon={CircleDollarSign}
            accent="teal"
          />
          <KpiCard
            label="Conversion Rate"
            value={`${kpis.conversion.value.toFixed(2)}%`}
            trend={kpis.conversion.trend}
            icon={Target}
            accent="rose"
          />
        </div>

        <div className="grid gap-5 xl:grid-cols-12">
          <SectionCard title="Leads & Orders Overview" className="xl:col-span-6">
            <TrendChart data={trend} />
          </SectionCard>

          <SectionCard
            title="Leads by Brand"
            className="xl:col-span-3"
            action={
              <Link
                className="text-xs font-semibold text-blue-600 hover:text-blue-700"
                href="/telesales/inventory"
              >
                View inventory
              </Link>
            }
          >
            <DonutBreakdown items={brandBreakdown} />
          </SectionCard>

          <SectionCard
            title="Leads by Region"
            className="xl:col-span-3"
            action={
              <Link
                className="text-xs font-semibold text-blue-600 hover:text-blue-700"
                href="/telesales/inventory"
              >
                View inventory
              </Link>
            }
          >
            <DonutBreakdown items={regionBreakdown} />
          </SectionCard>
        </div>

        <div className="grid gap-5 xl:grid-cols-2">
          <SectionCard
            title="Recent Pool Requests"
            action={
              <Link
                className="text-xs font-semibold text-blue-600 hover:text-blue-700"
                href="/telesales/pool-requests"
              >
                View all
              </Link>
            }
          >
            {recentPoolRequests.length === 0 ? (
              <div className="flex min-h-52 flex-col items-center justify-center text-center">
                <FilePlus2Empty className="mb-3 h-9 w-9 text-slate-300" />
                <p className="text-sm font-medium text-slate-600">No pool requests yet.</p>
                <p className="mt-1 text-xs text-slate-400">New requests will appear here.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[680px] text-left text-xs">
                  <thead>
                    <tr className="border-b border-slate-100 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                      <th className="pb-3 pr-4">Request #</th>
                      <th className="pb-3 pr-4">Brand</th>
                      <th className="pb-3 pr-4">Region</th>
                      <th className="pb-3 pr-4 text-right">Qty</th>
                      <th className="pb-3 pr-4">Status</th>
                      <th className="pb-3 pr-4">Requested By</th>
                      <th className="pb-3">Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    {recentPoolRequests.map((request) => (
                      <tr
                        key={request.id}
                        className="border-b border-slate-100 last:border-0 hover:bg-slate-50/70"
                      >
                        <td className="py-3 pr-4 font-mono text-[11px] font-semibold text-slate-700">
                          <Link
                            href={`/telesales/pool-requests/${request.id}`}
                            className="hover:text-blue-600"
                          >
                            {request.request_number}
                          </Link>
                        </td>
                        <td className="py-3 pr-4 font-semibold text-slate-800">
                          {request.brand_name}
                        </td>
                        <td className="py-3 pr-4 text-slate-500">{request.region}</td>
                        <td className="py-3 pr-4 text-right font-semibold tabular-nums text-slate-700">
                          {request.requested_quantity.toLocaleString()}
                        </td>
                        <td className="py-3 pr-4">
                          <StatusPill status={request.status} />
                        </td>
                        <td className="py-3 pr-4 text-slate-500">{request.requested_by}</td>
                        <td className="py-3 text-slate-400">
                          {request.created_at ? formatDate(request.created_at) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </SectionCard>

          <SectionCard
            title="Top Performing Agents"
            action={
              <Link
                className="text-xs font-semibold text-blue-600 hover:text-blue-700"
                href="/distribution/analytics"
              >
                View all
              </Link>
            }
          >
            {topAgents.length === 0 ? (
              <div className="flex min-h-52 flex-col items-center justify-center text-center">
                <UsersRound className="mb-3 h-9 w-9 text-slate-300" />
                <p className="text-sm font-medium text-slate-600">
                  No agent activity in this period.
                </p>
                <p className="mt-1 text-xs text-slate-400">
                  Agent rankings appear after calls and outcomes are recorded.
                </p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[520px] text-left text-xs">
                  <thead>
                    <tr className="border-b border-slate-100 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                      <th className="pb-3 pr-3">#</th>
                      <th className="pb-3 pr-4">Agent</th>
                      <th className="pb-3 pr-4 text-right">Contact Rate</th>
                      <th className="pb-3 pr-4 text-right">Orders</th>
                      <th className="pb-3 text-right">Conversion</th>
                    </tr>
                  </thead>
                  <tbody>
                    {topAgents.map((agent, index) => (
                      <tr
                        key={agent.id}
                        className="border-b border-slate-100 last:border-0 hover:bg-slate-50/70"
                      >
                        <td className="py-3 pr-3 font-semibold text-slate-400">{index + 1}</td>
                        <td className="py-3 pr-4">
                          <div className="flex items-center gap-2.5">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-600">
                              {agent.name
                                .split(/\s+/)
                                .slice(0, 2)
                                .map((part) => part[0])
                                .join('')
                                .toUpperCase()}
                            </div>
                            <span className="font-semibold text-slate-800">{agent.name}</span>
                          </div>
                        </td>
                        <td className="py-3 pr-4 text-right font-semibold tabular-nums text-slate-700">
                          {agent.contact_rate.toFixed(1)}%
                        </td>
                        <td className="py-3 pr-4 text-right font-semibold tabular-nums text-slate-700">
                          {agent.orders}
                        </td>
                        <td className="py-3 text-right font-bold tabular-nums text-emerald-600">
                          {agent.conversion.toFixed(1)}%
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </SectionCard>
        </div>

        <footer className="flex flex-col gap-1 border-t border-slate-200 pt-4 text-[11px] text-slate-400 sm:flex-row sm:items-center sm:justify-between">
          <span>WarehouseOps — Telesales Department</span>
          <span>Lead Distribution & Sales Operations</span>
        </footer>
      </div>
    </TelesalesLayout>
  );
}

function BarReportIcon({ className }: { className?: string }) {
  return <BarChart3 className={className} />;
}

function FilePlus2Empty({ className }: { className?: string }) {
  return <CheckCircle2 className={className} />;
}
