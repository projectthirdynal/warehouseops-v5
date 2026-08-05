import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  AlertTriangle,
  Download,
  Hourglass,
  RefreshCw,
  Skull,
  TrendingDown,
  Settings as SettingsIcon,
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
import { formatCurrency, cn } from '@/lib/utils';

interface ScanItem {
  stream: 'product' | 'supply';
  item_id: number;
  stock_id: number;
  sku: string;
  name: string;
  category: string;
  warehouse: string;
  warehouse_id: number;
  current_stock: number;
  reserved_stock: number;
  available_stock: number;
  unit_cost: number;
  total_value: number;
  last_movement_at: string | null;
  days_idle: number;
  bucket: 'slow' | 'non_moving' | 'dead';
}

interface Bucket {
  bucket: string;
  label: string;
  color: string;
  count: number;
  total_value: number;
  product_count: number;
  supply_count: number;
}

interface WarehouseBreakdown {
  warehouse_id: number;
  warehouse: string;
  total_items: number;
  total_value: number;
  dead_count: number;
  dead_value: number;
}

interface Summary {
  total_items: number;
  total_value: number;
  dead_count: number;
  dead_value: number;
  non_moving_count: number;
  non_moving_value: number;
  slow_count: number;
  slow_value: number;
  total_write_offs: number;
  total_write_off_value: number;
}

interface Settings {
  slow_days: number;
  non_moving_days: number;
  dead_days: number;
  auto_write_off: boolean;
  notify_emails: string;
  notify_email_enabled: boolean;
  notify_in_app_enabled: boolean;
  min_value_threshold: number;
  scan_frequency: string;
}

interface Dashboard {
  summary: Summary;
  buckets: Bucket[];
  by_warehouse: WarehouseBreakdown[];
  top_dead_items: ScanItem[];
  items: ScanItem[];
  settings: Settings;
}

interface Props {
  dashboard: Dashboard;
}

const bucketColor = (bucket: string) => {
  switch (bucket) {
    case 'dead':
      return 'destructive';
    case 'non_moving':
      return 'warning';
    case 'slow':
      return 'info';
    default:
      return 'muted';
  }
};

const bucketBadge = (bucket: string) => {
  switch (bucket) {
    case 'dead':
      return <Badge variant="destructive">Dead</Badge>;
    case 'non_moving':
      return <Badge variant="warning">Non-Moving</Badge>;
    case 'slow':
      return <Badge variant="secondary">Slow</Badge>;
    default:
      return <Badge variant="secondary">—</Badge>;
  }
};

export default function DeadStockAutomation({ dashboard }: Props) {
  const { summary, buckets, by_warehouse, top_dead_items, items, settings } = dashboard;

  const [showSettings, setShowSettings] = useState(false);
  const [scanning, setScanning] = useState(false);

  const { data, setData, patch, processing } = useForm({
    slow_days: String(settings.slow_days),
    non_moving_days: String(settings.non_moving_days),
    dead_days: String(settings.dead_days),
    auto_write_off: settings.auto_write_off,
    notify_emails: settings.notify_emails,
    notify_email_enabled: settings.notify_email_enabled,
    notify_in_app_enabled: settings.notify_in_app_enabled,
    min_value_threshold: String(settings.min_value_threshold),
    scan_frequency: settings.scan_frequency,
  });

  function handleScan() {
    setScanning(true);
    router.post(
      '/inventory/dead-stock-automation/scan',
      {},
      {
        preserveState: false,
        onFinish: () => setScanning(false),
      }
    );
  }

  function handleExport() {
    window.location.href = '/inventory/dead-stock-automation/export';
  }

  function saveSettings() {
    patch('/inventory/dead-stock-automation/settings', { preserveState: true });
  }

  return (
    <AppLayout>
      <Head title="Dead Stock Automation" />
      <div className="space-y-5 p-6">
        {/* Header */}
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1 flex items-center gap-1 text-xs text-muted-foreground">
              <Link href="/inventory" className="hover:text-foreground">
                Inventory
              </Link>
              <span>/</span>
              <span>Dead Stock Automation</span>
            </div>
            <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
              <Skull className="h-5 w-5 text-destructive" />
              Dead Stock Automation
            </h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Scheduled scan with aging buckets, auto-flagging, and notifications.
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
            <Button onClick={handleScan} disabled={scanning}>
              <RefreshCw className={cn('mr-1.5 h-4 w-4', scanning && 'animate-spin')} />
              {scanning ? 'Scanning...' : 'Run Scan'}
            </Button>
          </div>
        </div>

        {/* Summary stats */}
        <div className="grid grid-cols-4 gap-4">
          <StatCard
            label="Total Idle Items"
            value={String(summary.total_items)}
            sub={formatCurrency(summary.total_value)}
            icon={<Hourglass className="h-4 w-4" />}
            accent="info"
          />
          <StatCard
            label="Dead Stock"
            value={String(summary.dead_count)}
            sub={formatCurrency(summary.dead_value)}
            icon={<Skull className="h-4 w-4" />}
            accent="destructive"
          />
          <StatCard
            label="Non-Moving"
            value={String(summary.non_moving_count)}
            sub={formatCurrency(summary.non_moving_value)}
            icon={<TrendingDown className="h-4 w-4" />}
            accent="warning"
          />
          <StatCard
            label="Write-Offs (All Time)"
            value={String(summary.total_write_offs)}
            sub={formatCurrency(summary.total_write_off_value)}
            icon={<AlertTriangle className="h-4 w-4" />}
            accent="info"
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
                  <Label className="text-xs">Slow Days</Label>
                  <Input
                    type="number"
                    value={data.slow_days}
                    onChange={(e) => setData('slow_days', e.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">
                    Items idle this many days are "Slow"
                  </p>
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Non-Moving Days</Label>
                  <Input
                    type="number"
                    value={data.non_moving_days}
                    onChange={(e) => setData('non_moving_days', e.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">
                    Items idle this many days are "Non-Moving"
                  </p>
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Dead Days</Label>
                  <Input
                    type="number"
                    value={data.dead_days}
                    onChange={(e) => setData('dead_days', e.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">
                    Items idle this many days are "Dead"
                  </p>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1">
                  <Label className="text-xs">Min Value Threshold</Label>
                  <Input
                    type="number"
                    step="0.01"
                    value={data.min_value_threshold}
                    onChange={(e) => setData('min_value_threshold', e.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">
                    Only flag items worth at least this much
                  </p>
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Scan Frequency</Label>
                  <Select
                    value={data.scan_frequency}
                    onValueChange={(v) => setData('scan_frequency', v)}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="daily">Daily</SelectItem>
                      <SelectItem value="weekly">Weekly</SelectItem>
                      <SelectItem value="monthly">Monthly</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="flex items-center gap-6">
                <div className="flex items-center gap-2">
                  <Switch
                    checked={data.auto_write_off}
                    onCheckedChange={(v) => setData('auto_write_off', v)}
                  />
                  <Label className="text-xs">Auto Write-Off Dead Items</Label>
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
                  placeholder="admin@example.com, warehouse@example.com"
                />
              </div>

              <Button onClick={saveSettings} disabled={processing}>
                Save Settings
              </Button>
            </CardContent>
          </Card>
        )}

        {/* Aging buckets */}
        <div className="grid grid-cols-3 gap-4">
          {buckets.map((b) => (
            <Card key={b.bucket}>
              <CardContent className="p-4">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium">{b.label}</span>
                  <Badge variant={bucketColor(b.bucket) as 'destructive' | 'warning' | 'secondary'}>
                    {b.count}
                  </Badge>
                </div>
                <div className="mt-2 text-xl font-bold">{formatCurrency(b.total_value)}</div>
                <div className="mt-0.5 text-xs text-muted-foreground">
                  {b.product_count} products · {b.supply_count} supplies
                </div>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* By warehouse */}
        {by_warehouse.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">By Warehouse</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Warehouse</TableHead>
                    <TableHead className="text-right">Total Items</TableHead>
                    <TableHead className="text-right">Total Value</TableHead>
                    <TableHead className="text-right">Dead Count</TableHead>
                    <TableHead className="text-right">Dead Value</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {by_warehouse.map((w) => (
                    <TableRow key={w.warehouse_id}>
                      <TableCell className="font-medium">{w.warehouse}</TableCell>
                      <TableCell className="text-right">{w.total_items}</TableCell>
                      <TableCell className="text-right">{formatCurrency(w.total_value)}</TableCell>
                      <TableCell className="text-right">
                        <Badge variant="destructive">{w.dead_count}</Badge>
                      </TableCell>
                      <TableCell className="text-right font-bold text-destructive">
                        {formatCurrency(w.dead_value)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Top dead items */}
        {top_dead_items.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-sm">
                <Skull className="h-4 w-4 text-destructive" />
                Top Dead Stock Items
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Item</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Warehouse</TableHead>
                    <TableHead className="text-right">Stock</TableHead>
                    <TableHead className="text-right">Days Idle</TableHead>
                    <TableHead className="text-right">Unit Cost</TableHead>
                    <TableHead className="text-right">Total Value</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {top_dead_items.map((item) => (
                    <TableRow key={`${item.stream}-${item.stock_id}`}>
                      <TableCell>
                        <div className="font-medium">{item.name}</div>
                        <div className="text-xs text-muted-foreground">{item.sku}</div>
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline">{item.stream}</Badge>
                      </TableCell>
                      <TableCell className="text-sm">{item.warehouse}</TableCell>
                      <TableCell className="text-right">{item.current_stock}</TableCell>
                      <TableCell className="text-right">
                        <span className="font-medium text-destructive">{item.days_idle}d</span>
                      </TableCell>
                      <TableCell className="text-right">{formatCurrency(item.unit_cost)}</TableCell>
                      <TableCell className="text-right font-bold text-destructive">
                        {formatCurrency(item.total_value)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* All idle items */}
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">All Idle Stock Items</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Item</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>Warehouse</TableHead>
                  <TableHead className="text-right">Stock</TableHead>
                  <TableHead className="text-right">Available</TableHead>
                  <TableHead className="text-right">Days Idle</TableHead>
                  <TableHead className="text-right">Total Value</TableHead>
                  <TableHead>Bucket</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.slice(0, 50).map((item) => (
                  <TableRow key={`${item.stream}-${item.stock_id}`}>
                    <TableCell>
                      <div className="font-medium">{item.name}</div>
                      <div className="text-xs text-muted-foreground">{item.sku}</div>
                    </TableCell>
                    <TableCell>
                      <Badge variant="outline">{item.stream}</Badge>
                    </TableCell>
                    <TableCell className="text-sm">{item.category}</TableCell>
                    <TableCell className="text-sm">{item.warehouse}</TableCell>
                    <TableCell className="text-right">{item.current_stock}</TableCell>
                    <TableCell className="text-right text-muted-foreground">
                      {item.available_stock}
                    </TableCell>
                    <TableCell className="text-right">{item.days_idle}d</TableCell>
                    <TableCell className="text-right font-bold">
                      {formatCurrency(item.total_value)}
                    </TableCell>
                    <TableCell>{bucketBadge(item.bucket)}</TableCell>
                  </TableRow>
                ))}
                {items.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={9} className="py-8 text-center text-muted-foreground">
                      No idle stock items found. All inventory is moving!
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
            {items.length > 50 && (
              <div className="border-t p-3 text-center text-xs text-muted-foreground">
                Showing 50 of {items.length} items. Export CSV for full list.
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
