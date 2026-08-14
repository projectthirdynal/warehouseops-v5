import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  TrendingDown,
  CalendarClock,
  CheckCircle2,
  AlertCircle,
  Download,
  RefreshCw,
  Settings as SettingsIcon,
  Building2,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { formatCurrency, cn } from '@/lib/utils';

interface DueEntry {
  id: number;
  asset_code: string;
  asset_name: string;
  category: string;
  year: number;
  month: number;
  posting_date: string;
  depreciation_amount: number;
  book_value_after: number;
  debit_account: string;
  credit_account: string;
  reference: string;
}

interface UpcomingEntry {
  id: number;
  asset_code: string;
  asset_name: string;
  category: string;
  year: number;
  month: number;
  posting_date: string;
  depreciation_amount: number;
  debit_account: string;
  credit_account: string;
  reference: string;
}

interface AssetSummary {
  id: number;
  asset_code: string;
  name: string;
  category: string;
  acquisition_cost: number;
  current_book_value: number;
  accumulated_depreciation: number;
  annual_depreciation: number;
  posted_count: number;
  due_count: number;
}

interface TrendPoint {
  period: string;
  total_amount: number;
  entry_count: number;
}

interface Summary {
  total_assets: number;
  total_acquisition_cost: number;
  total_book_value: number;
  total_accumulated_depreciation: number;
  due_count: number;
  due_amount: number;
  posted_count: number;
  posted_amount: number;
}

interface Settings {
  auto_post: boolean;
  posting_day: number;
  debit_account: string;
  credit_account: string;
  notify_emails: string;
  notify_email_enabled: boolean;
  notify_in_app_enabled: boolean;
}

interface Dashboard {
  summary: Summary;
  upcoming: UpcomingEntry[];
  due_entries: DueEntry[];
  by_asset: AssetSummary[];
  monthly_trend: TrendPoint[];
  settings: Settings;
}

interface Props {
  dashboard: Dashboard;
}

const monthName = (m: number) =>
  ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][m - 1] ?? '';

export default function DepreciationAutomation({ dashboard }: Props) {
  const { summary, upcoming, due_entries, by_asset, monthly_trend, settings } = dashboard;

  const [showSettings, setShowSettings] = useState(false);
  const [posting, setPosting] = useState(false);

  const { data, setData, patch, processing } = useForm({
    auto_post: settings.auto_post,
    posting_day: String(settings.posting_day),
    debit_account: settings.debit_account,
    credit_account: settings.credit_account,
    notify_emails: settings.notify_emails,
    notify_email_enabled: settings.notify_email_enabled,
    notify_in_app_enabled: settings.notify_in_app_enabled,
  });

  function handlePost() {
    setPosting(true);
    router.post(
      '/inventory/depreciation-automation/post',
      {},
      {
        preserveState: false,
        onFinish: () => setPosting(false),
      }
    );
  }

  function handleExport() {
    window.location.href = '/inventory/depreciation-automation/export';
  }

  function saveSettings() {
    patch('/inventory/depreciation-automation/settings', { preserveState: true });
  }

  return (
    <AppLayout>
      <Head title="Asset Depreciation Automation" />
      <div className="space-y-5 p-6">
        {/* Header */}
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1 flex items-center gap-1 text-xs text-muted-foreground">
              <Link href="/inventory" className="hover:text-foreground">
                Inventory
              </Link>
              <span>/</span>
              <span>Depreciation Automation</span>
            </div>
            <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
              <TrendingDown className="h-5 w-5 text-info" />
              Asset Depreciation Automation
            </h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Scheduled monthly depreciation posting with journal entries.
            </p>
          </div>
          <div className="flex gap-2">
            <Button onClick={handleExport} variant="outline">
              <Download className="mr-1.5 h-4 w-4" />
              Export CSV
            </Button>
            <Button onClick={() => setShowSettings(!showSettings)} variant="outline">
              <SettingsIcon className="mr-1.5 h-4 w-4" />
              Settings
            </Button>
            <Button onClick={handlePost} disabled={posting}>
              <RefreshCw className={cn('mr-1.5 h-4 w-4', posting && 'animate-spin')} />
              {posting ? 'Posting...' : 'Post Now'}
            </Button>
          </div>
        </div>

        {/* Summary stats */}
        <div className="grid grid-cols-4 gap-4">
          <StatCard
            label="Total Assets"
            value={String(summary.total_assets)}
            sub={formatCurrency(summary.total_acquisition_cost)}
            icon={<Building2 className="h-4 w-4" />}
            accent="info"
          />
          <StatCard
            label="Book Value"
            value={formatCurrency(summary.total_book_value)}
            sub={`Acc. Dep: ${formatCurrency(summary.total_accumulated_depreciation)}`}
            icon={<TrendingDown className="h-4 w-4" />}
            accent="info"
          />
          <StatCard
            label="Due to Post"
            value={String(summary.due_count)}
            sub={formatCurrency(summary.due_amount)}
            icon={<AlertCircle className="h-4 w-4" />}
            accent="warning"
          />
          <StatCard
            label="Posted (All Time)"
            value={String(summary.posted_count)}
            sub={formatCurrency(summary.posted_amount)}
            icon={<CheckCircle2 className="h-4 w-4" />}
            accent="success"
          />
        </div>

        {/* Settings panel */}
        {showSettings && (
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Automation Settings</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-3 gap-4">
                <div className="space-y-1">
                  <Label className="text-xs">Posting Day (of month)</Label>
                  <Input
                    type="number"
                    min={1}
                    max={28}
                    value={data.posting_day}
                    onChange={(e) => setData('posting_day', e.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">
                    Day of month to post entries (1–28)
                  </p>
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Debit Account</Label>
                  <Input
                    value={data.debit_account}
                    onChange={(e) => setData('debit_account', e.target.value)}
                  />
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Credit Account</Label>
                  <Input
                    value={data.credit_account}
                    onChange={(e) => setData('credit_account', e.target.value)}
                  />
                </div>
              </div>

              <div className="flex items-center gap-6">
                <div className="flex items-center gap-2">
                  <Switch
                    checked={data.auto_post}
                    onCheckedChange={(v) => setData('auto_post', v)}
                  />
                  <Label className="text-xs">Auto-Post Monthly</Label>
                </div>
                <div className="flex items-center gap-2">
                  <Switch
                    checked={data.notify_email_enabled}
                    onCheckedChange={(v) => setData('notify_email_enabled', v)}
                  />
                  <Label className="text-xs">Email Notifications</Label>
                </div>
                <div className="flex items-center gap-2">
                  <Switch
                    checked={data.notify_in_app_enabled}
                    onCheckedChange={(v) => setData('notify_in_app_enabled', v)}
                  />
                  <Label className="text-xs">In-App Notifications</Label>
                </div>
              </div>

              <div className="space-y-1">
                <Label className="text-xs">Notification Emails (comma-separated)</Label>
                <Textarea
                  rows={2}
                  value={data.notify_emails}
                  onChange={(e) => setData('notify_emails', e.target.value)}
                  placeholder="finance@example.com, admin@example.com"
                />
              </div>

              <Button onClick={saveSettings} disabled={processing}>
                Save Settings
              </Button>
            </CardContent>
          </Card>
        )}

        {/* Monthly trend */}
        {monthly_trend.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Monthly Depreciation Trend (Last 12 Months)</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Period</TableHead>
                    <TableHead className="text-right">Entries</TableHead>
                    <TableHead className="text-right">Total Amount</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {monthly_trend.map((t) => (
                    <TableRow key={t.period}>
                      <TableCell className="font-medium">{t.period}</TableCell>
                      <TableCell className="text-right">{t.entry_count}</TableCell>
                      <TableCell className="text-right font-bold">
                        {formatCurrency(t.total_amount)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Due entries */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm">
              <AlertCircle className="h-4 w-4 text-warning" />
              Due Depreciation Entries ({due_entries.length})
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Reference</TableHead>
                  <TableHead>Asset</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead className="text-right">Period</TableHead>
                  <TableHead>Posting Date</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead className="text-right">Book Value After</TableHead>
                  <TableHead>Accounts</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {due_entries.slice(0, 25).map((e) => (
                  <TableRow key={e.id}>
                    <TableCell className="font-mono text-xs">{e.reference}</TableCell>
                    <TableCell>
                      <div className="font-medium">{e.asset_name}</div>
                      <div className="text-xs text-muted-foreground">{e.asset_code}</div>
                    </TableCell>
                    <TableCell>
                      <Badge variant="outline">{e.category}</Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      {monthName(e.month)} {e.year}
                    </TableCell>
                    <TableCell className="text-sm">{e.posting_date}</TableCell>
                    <TableCell className="text-right font-bold text-warning">
                      {formatCurrency(e.depreciation_amount)}
                    </TableCell>
                    <TableCell className="text-right">
                      {formatCurrency(e.book_value_after)}
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      Dr: {e.debit_account}
                      <br />
                      Cr: {e.credit_account}
                    </TableCell>
                  </TableRow>
                ))}
                {due_entries.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                      No due depreciation entries. All caught up!
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
            {due_entries.length > 25 && (
              <div className="border-t p-3 text-center text-xs text-muted-foreground">
                Showing 25 of {due_entries.length} due entries. Export CSV for full list.
              </div>
            )}
          </CardContent>
        </Card>

        {/* Upcoming entries */}
        {upcoming.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-sm">
                <CalendarClock className="h-4 w-4 text-info" />
                Upcoming Entries (Next 30 Days)
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Reference</TableHead>
                    <TableHead>Asset</TableHead>
                    <TableHead className="text-right">Period</TableHead>
                    <TableHead>Posting Date</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {upcoming.map((e) => (
                    <TableRow key={e.id}>
                      <TableCell className="font-mono text-xs">{e.reference}</TableCell>
                      <TableCell>
                        <div className="font-medium">{e.asset_name}</div>
                        <div className="text-xs text-muted-foreground">{e.asset_code}</div>
                      </TableCell>
                      <TableCell className="text-right">
                        {monthName(e.month)} {e.year}
                      </TableCell>
                      <TableCell className="text-sm">{e.posting_date}</TableCell>
                      <TableCell className="text-right">
                        {formatCurrency(e.depreciation_amount)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* By asset */}
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Depreciation by Asset</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Asset</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead className="text-right">Acquisition Cost</TableHead>
                  <TableHead className="text-right">Book Value</TableHead>
                  <TableHead className="text-right">Acc. Depreciation</TableHead>
                  <TableHead className="text-right">Annual Dep.</TableHead>
                  <TableHead className="text-right">Posted</TableHead>
                  <TableHead className="text-right">Due</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {by_asset.slice(0, 30).map((a) => (
                  <TableRow key={a.id}>
                    <TableCell>
                      <div className="font-medium">{a.name}</div>
                      <div className="text-xs text-muted-foreground">{a.asset_code}</div>
                    </TableCell>
                    <TableCell>
                      <Badge variant="outline">{a.category}</Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      {formatCurrency(a.acquisition_cost)}
                    </TableCell>
                    <TableCell className="text-right font-bold">
                      {formatCurrency(a.current_book_value)}
                    </TableCell>
                    <TableCell className="text-right text-muted-foreground">
                      {formatCurrency(a.accumulated_depreciation)}
                    </TableCell>
                    <TableCell className="text-right">
                      {formatCurrency(a.annual_depreciation)}
                    </TableCell>
                    <TableCell className="text-right">
                      <Badge variant="secondary">{a.posted_count}</Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      {a.due_count > 0 ? (
                        <Badge variant="warning">{a.due_count}</Badge>
                      ) : (
                        <Badge variant="secondary">0</Badge>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
                {by_asset.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                      No active assets found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
            {by_asset.length > 30 && (
              <div className="border-t p-3 text-center text-xs text-muted-foreground">
                Showing 30 of {by_asset.length} assets. Export CSV for full list.
              </div>
            )}
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
          <span className="text-xs font-medium uppercase text-muted-foreground">{label}</span>
          <span className={accentClass}>{icon}</span>
        </div>
        <div className="mt-2 text-xl font-bold">{value}</div>
        <div className="mt-0.5 text-xs text-muted-foreground">{sub}</div>
      </CardContent>
    </Card>
  );
}
