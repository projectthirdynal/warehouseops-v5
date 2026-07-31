import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card } from '@/components/ui/card';
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
  AlertTriangle,
  Clock,
  Wallet,
  ShieldCheck,
  FileWarning,
  Settings as SettingsIcon,
  Loader2,
  ChevronRight,
} from 'lucide-react';
import { toast } from 'sonner';
import axios from 'axios';
import { formatDate } from '@/lib/utils';

interface Summary {
  total_breaches: number;
  cod_at_risk: number;
  avg_days_overdue: number;
  compliance_rate: number;
  resolved_in_sla: number;
  total_returned: number;
  critical_count: number;
  claims_filed: number;
  claims_pending: number;
}

interface CourierRow {
  courier: string;
  breach_count: number;
  cod_at_risk: number;
  avg_days_overdue: number;
  critical_count: number;
}

interface AgingBucket {
  label: string;
  count: number;
  cod_value: number;
}

interface TrendPoint {
  date: string;
  new_breaches: number;
  resolved: number;
}

interface BreachRow {
  id: number;
  waybill_number: string;
  courier: string;
  receiver_name: string;
  city: string;
  cod_amount: number;
  returned_at: string | null;
  days_overdue: number;
  has_claim: boolean;
  claim_count: number;
}

interface SlaSettings {
  sla_return_days: number;
}

interface Props {
  summary: Summary;
  by_courier: CourierRow[];
  aging_buckets: AgingBucket[];
  trend: TrendPoint[];
  recent_breaches: BreachRow[];
  settings: SlaSettings;
  filters: { from?: string; to?: string; courier?: string };
}

function formatPeso(amount: number): string {
  return (
    '₱' +
    Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  );
}

function trendDateLabel(dateStr: string): string {
  const d = new Date(dateStr + 'T00:00:00+08:00');
  return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', timeZone: 'Asia/Manila' });
}

export default function SlaDashboard({
  summary,
  by_courier,
  aging_buckets,
  trend,
  recent_breaches,
  settings,
  filters,
}: Props) {
  const [courierFilter, setCourierFilter] = useState(filters.courier ?? 'all');
  const [liveData, setLiveData] = useState({
    summary,
    by_courier,
    aging_buckets,
    trend,
    recent_breaches,
  });
  const [loading, setLoading] = useState(false);
  const [showSettings, setShowSettings] = useState(false);
  const [slaDays, setSlaDays] = useState(settings.sla_return_days);
  const [savingSettings, setSavingSettings] = useState(false);

  function fetchDashboard(courier?: string) {
    setLoading(true);
    const params = new URLSearchParams();
    if (courier && courier !== 'all') params.set('courier', courier);
    axios
      .get(`/waybills/sla-dashboard/api?${params.toString()}`)
      .then(({ data }) => {
        setLiveData({
          summary: data.summary,
          by_courier: data.by_courier,
          aging_buckets: data.aging_buckets,
          trend: data.trend,
          recent_breaches: data.recent_breaches,
        });
      })
      .catch(() => toast.error('Failed to refresh dashboard'))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    fetchDashboard(courierFilter);
  }, [courierFilter]);

  function handleSaveSettings() {
    setSavingSettings(true);
    axios
      .patch('/waybills/sla-dashboard/settings', { sla_return_days: slaDays })
      .then(() => {
        toast.success('SLA settings updated');
        setShowSettings(false);
        fetchDashboard(courierFilter);
      })
      .catch(() => toast.error('Failed to update settings'))
      .finally(() => setSavingSettings(false));
  }

  const maxTrendVal = Math.max(...trend.flatMap((t) => [t.new_breaches, t.resolved]), 1);
  const maxAgingCount = Math.max(...aging_buckets.map((b) => b.count), 1);

  return (
    <AppLayout>
      <Head title="SLA Dashboard" />

      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-bold font-display">SLA Dashboard</h1>
            <p className="text-sm text-muted-foreground">
              Beyond-SLA monitoring per courier — returned parcels not yet received at warehouse
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Select value={courierFilter} onValueChange={setCourierFilter}>
              <SelectTrigger className="w-40">
                <SelectValue placeholder="All Couriers" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Couriers</SelectItem>
                <SelectItem value="J&T">J&T Express</SelectItem>
                <SelectItem value="FLASH">Flash Express</SelectItem>
              </SelectContent>
            </Select>
            <Button variant="outline" size="sm" onClick={() => setShowSettings((v) => !v)}>
              <SettingsIcon className="mr-1.5 h-4 w-4" />
              Settings
            </Button>
            <Link href="/waybills/claims/beyond-sla">
              <Button variant="outline" size="sm">
                Beyond SLA List
                <ChevronRight className="ml-1 h-4 w-4" />
              </Button>
            </Link>
          </div>
        </div>

        {/* Settings Panel */}
        {showSettings && (
          <Card className="p-4 space-y-3">
            <div className="flex items-center gap-2">
              <SettingsIcon className="h-4 w-4 text-muted-foreground" />
              <span className="text-sm font-medium">SLA Settings</span>
            </div>
            <div className="flex items-end gap-3">
              <div className="space-y-1">
                <label className="text-xs text-muted-foreground">SLA Return Days</label>
                <Input
                  type="number"
                  min={1}
                  max={30}
                  value={slaDays}
                  onChange={(e) => setSlaDays(Number(e.target.value))}
                  className="w-32"
                />
              </div>
              <Button size="sm" onClick={handleSaveSettings} disabled={savingSettings}>
                {savingSettings && <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />}
                Save
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">
              Parcels returned more than this many days ago without a return receipt are flagged as
              beyond SLA.
            </p>
          </Card>
        )}

        {/* Summary Stats */}
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-5">
          <StatCard
            icon={<AlertTriangle className="h-5 w-5 text-destructive" />}
            label="Total Breaches"
            value={String(liveData.summary.total_breaches)}
            sublabel={`${liveData.summary.critical_count} critical (>7d)`}
            tone="destructive"
          />
          <StatCard
            icon={<Wallet className="h-5 w-5 text-warning" />}
            label="COD at Risk"
            value={formatPeso(liveData.summary.cod_at_risk)}
            sublabel="Unrecovered COD"
            tone="warning"
          />
          <StatCard
            icon={<Clock className="h-5 w-5 text-info" />}
            label="Avg Days Overdue"
            value={`${liveData.summary.avg_days_overdue}d`}
            sublabel="Across all breaches"
            tone="info"
          />
          <StatCard
            icon={<ShieldCheck className="h-5 w-5 text-success" />}
            label="Compliance Rate"
            value={`${liveData.summary.compliance_rate}%`}
            sublabel={`${liveData.summary.resolved_in_sla}/${liveData.summary.total_returned} resolved`}
            tone="success"
          />
          <StatCard
            icon={<FileWarning className="h-5 w-5 text-muted-foreground" />}
            label="Claims Pending"
            value={String(liveData.summary.claims_pending)}
            sublabel={`${liveData.summary.claims_filed} filed`}
            tone="muted"
          />
        </div>

        {/* Per-Courier Breakdown + Aging Buckets */}
        <div className="grid gap-4 lg:grid-cols-2">
          {/* Per-Courier */}
          <Card className="p-4 space-y-3">
            <h2 className="text-sm font-semibold">Per-Courier Breakdown</h2>
            {liveData.by_courier.length === 0 ? (
              <p className="text-sm text-muted-foreground py-8 text-center">
                No breaches recorded.
              </p>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Courier</TableHead>
                    <TableHead className="text-right">Breaches</TableHead>
                    <TableHead className="text-right">COD at Risk</TableHead>
                    <TableHead className="text-right">Avg Overdue</TableHead>
                    <TableHead className="text-right">Critical</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {liveData.by_courier.map((row) => (
                    <TableRow key={row.courier}>
                      <TableCell className="font-medium">{row.courier}</TableCell>
                      <TableCell className="text-right">
                        <Badge variant={row.breach_count > 0 ? 'destructive' : 'secondary'}>
                          {row.breach_count}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right text-sm">
                        {formatPeso(row.cod_at_risk)}
                      </TableCell>
                      <TableCell className="text-right text-sm">{row.avg_days_overdue}d</TableCell>
                      <TableCell className="text-right">
                        {row.critical_count > 0 ? (
                          <Badge variant="destructive">{row.critical_count}</Badge>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Card>

          {/* Aging Buckets */}
          <Card className="p-4 space-y-3">
            <h2 className="text-sm font-semibold">Aging Buckets</h2>
            <div className="space-y-2">
              {aging_buckets.map((bucket) => (
                <div key={bucket.label} className="space-y-1">
                  <div className="flex items-center justify-between text-sm">
                    <span className="font-medium">{bucket.label}</span>
                    <span className="text-muted-foreground">
                      {bucket.count} parcels · {formatPeso(bucket.cod_value)}
                    </span>
                  </div>
                  <div className="h-2 rounded-full bg-muted overflow-hidden">
                    <div
                      className={`h-full rounded-full ${
                        bucket.label.includes('30')
                          ? 'bg-destructive'
                          : bucket.label.includes('15')
                            ? 'bg-warning'
                            : bucket.label.includes('8')
                              ? 'bg-orange-400'
                              : 'bg-info'
                      }`}
                      style={{ width: `${(bucket.count / maxAgingCount) * 100}%` }}
                    />
                  </div>
                </div>
              ))}
            </div>
          </Card>
        </div>

        {/* 30-Day Trend */}
        <Card className="p-4 space-y-3">
          <h2 className="text-sm font-semibold">30-Day Trend — New Breaches vs Resolved</h2>
          <div className="flex items-end gap-1 h-40">
            {liveData.trend.map((point) => (
              <div
                key={point.date}
                className="flex-1 flex flex-col items-center gap-0.5 group relative"
              >
                {/* New breaches bar */}
                <div
                  className="w-full rounded-t-sm bg-destructive/70 group-hover:bg-destructive transition-colors"
                  style={{ height: `${(point.new_breaches / maxTrendVal) * 120}px` }}
                  title={`New: ${point.new_breaches}`}
                />
                {/* Resolved bar */}
                <div
                  className="w-full rounded-t-sm bg-success/60 group-hover:bg-success transition-colors"
                  style={{ height: `${(point.resolved / maxTrendVal) * 120}px` }}
                  title={`Resolved: ${point.resolved}`}
                />
                {/* Tooltip */}
                <div className="absolute -top-12 hidden group-hover:block z-10 rounded-md border bg-popover px-2 py-1 text-xs shadow-md whitespace-nowrap">
                  <div className="font-medium">{trendDateLabel(point.date)}</div>
                  <div className="text-destructive">New: {point.new_breaches}</div>
                  <div className="text-success">Resolved: {point.resolved}</div>
                </div>
              </div>
            ))}
          </div>
          <div className="flex items-center justify-between text-xs text-muted-foreground">
            <span>{trendDateLabel(liveData.trend[0]?.date ?? '')}</span>
            <div className="flex items-center gap-4">
              <span className="flex items-center gap-1">
                <span className="inline-block h-2 w-2 rounded-sm bg-destructive/70" /> New Breaches
              </span>
              <span className="flex items-center gap-1">
                <span className="inline-block h-2 w-2 rounded-sm bg-success/60" /> Resolved
              </span>
            </div>
            <span>{trendDateLabel(liveData.trend[liveData.trend.length - 1]?.date ?? '')}</span>
          </div>
        </Card>

        {/* Recent Breaches Table */}
        <Card className="p-4 space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold">Recent Breaches</h2>
            {loading && <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />}
          </div>
          {liveData.recent_breaches.length === 0 ? (
            <p className="text-sm text-muted-foreground py-8 text-center">
              No beyond-SLA parcels. All returned parcels have been received.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Waybill #</TableHead>
                  <TableHead>Courier</TableHead>
                  <TableHead>Receiver</TableHead>
                  <TableHead className="text-right">COD</TableHead>
                  <TableHead>Returned</TableHead>
                  <TableHead className="text-right">Days Overdue</TableHead>
                  <TableHead>Claim</TableHead>
                  <TableHead></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {liveData.recent_breaches.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell>
                      <Link
                        href={`/waybills/${row.id}`}
                        className="font-mono text-sm font-medium hover:underline"
                      >
                        {row.waybill_number}
                      </Link>
                    </TableCell>
                    <TableCell className="text-sm">{row.courier}</TableCell>
                    <TableCell className="text-sm">{row.receiver_name}</TableCell>
                    <TableCell className="text-right text-sm font-medium">
                      {formatPeso(row.cod_amount)}
                    </TableCell>
                    <TableCell className="text-sm">
                      {row.returned_at ? formatDate(row.returned_at) : '—'}
                    </TableCell>
                    <TableCell className="text-right">
                      <Badge
                        variant={row.days_overdue >= 7 ? 'destructive' : 'secondary'}
                        className="text-xs"
                      >
                        {row.days_overdue}d
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {row.has_claim ? (
                        <Badge variant="outline" className="text-xs">
                          {row.claim_count} claim{row.claim_count > 1 ? 's' : ''}
                        </Badge>
                      ) : (
                        <span className="text-muted-foreground/50 text-xs">None</span>
                      )}
                    </TableCell>
                    <TableCell>
                      <Link href={`/waybills/claims/create?waybill_id=${row.id}&type=BEYOND_SLA`}>
                        <Button size="sm" variant="outline">
                          File Claim
                        </Button>
                      </Link>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
          <div className="flex justify-end">
            <Link href="/waybills/claims/beyond-sla">
              <Button variant="ghost" size="sm">
                View All Breaches
                <ChevronRight className="ml-1 h-4 w-4" />
              </Button>
            </Link>
          </div>
        </Card>
      </div>
    </AppLayout>
  );
}

function StatCard({
  icon,
  label,
  value,
  sublabel,
  tone,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  sublabel: string;
  tone: 'destructive' | 'warning' | 'info' | 'success' | 'muted';
}) {
  const toneClasses: Record<string, string> = {
    destructive: 'border-destructive/20 bg-destructive/5',
    warning: 'border-warning/20 bg-warning/5',
    info: 'border-info/20 bg-info/5',
    success: 'border-success/20 bg-success/5',
    muted: 'border-muted/20 bg-muted/5',
  };

  return (
    <Card className={`p-4 ${toneClasses[tone]}`}>
      <div className="flex items-center justify-between mb-2">
        <span className="text-xs font-medium text-muted-foreground">{label}</span>
        {icon}
      </div>
      <div className="text-2xl font-bold font-display">{value}</div>
      <div className="text-xs text-muted-foreground mt-1">{sublabel}</div>
    </Card>
  );
}
