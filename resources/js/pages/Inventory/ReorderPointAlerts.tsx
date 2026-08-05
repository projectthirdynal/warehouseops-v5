import { useState } from 'react';
import { toast } from 'sonner';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog';
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
  Bell,
  CheckCircle,
  Mail,
  RefreshCw,
  Settings as SettingsIcon,
  ShoppingCart,
} from 'lucide-react';
import Paginator from '@/components/Paginator';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface AlertRow {
  id: number;
  stream: 'product' | 'supply';
  item_name: string;
  item_sku: string;
  warehouse: string;
  warehouse_id: number;
  current_stock: number;
  reserved_stock: number;
  available_stock: number;
  reorder_point: number;
  suggested_reorder_qty: number;
  status: string;
  acknowledged_by?: string | null;
  acknowledged_at?: string | null;
  notes?: string | null;
  created_at: string;
}

interface Summary {
  total_open: number;
  total_acknowledged: number;
  total_resolved: number;
  product_alerts: number;
  supply_alerts: number;
  by_warehouse: { id: number; name: string; code: string; open_alerts: number }[];
}

interface Settings {
  notify_emails: string;
  notify_roles: string[];
  notify_email_enabled: boolean;
  notify_in_app_enabled: boolean;
  scan_frequency: string;
  reorder_multiplier: number;
}

interface Props {
  alerts: PaginatedResponse<AlertRow>;
  summary: Summary;
  settings: Settings;
  warehouses: { id: number; name: string; code: string }[];
  filters: Record<string, string | undefined>;
  auth: { user: { role: string } };
}

const statusColors: Record<string, string> = {
  OPEN: 'bg-destructive text-white',
  ACKNOWLEDGED: 'bg-warning text-white',
  RESOLVED: 'bg-success text-white',
};

const ALL_ROLES = [
  { value: 'superadmin', label: 'Super Admin' },
  { value: 'admin', label: 'Admin' },
  { value: 'supervisor', label: 'Supervisor' },
  { value: 'warehouse', label: 'Warehouse' },
  { value: 'finance', label: 'Finance' },
  { value: 'accounting', label: 'Accounting' },
];

export default function ReorderPointAlerts({
  alerts,
  summary,
  settings,
  warehouses,
  filters,
  auth,
}: Props) {
  const [scanning, setScanning] = useState(false);
  const [ackId, setAckId] = useState<number | null>(null);
  const [ackNotes, setAckNotes] = useState('');
  const [showSettings, setShowSettings] = useState(false);

  const canManage = ['superadmin', 'admin', 'supervisor', 'warehouse'].includes(auth.user.role);

  const { data, setData, patch, processing } = useForm({
    notify_emails: settings.notify_emails,
    notify_roles: settings.notify_roles,
    notify_email_enabled: settings.notify_email_enabled,
    notify_in_app_enabled: settings.notify_in_app_enabled,
    scan_frequency: settings.scan_frequency,
    reorder_multiplier: String(settings.reorder_multiplier),
  });

  function applyFilters(overrides: Record<string, string>) {
    router.get(
      '/inventory/reorder-alerts',
      { ...filters, ...overrides },
      { preserveState: true, replace: true }
    );
  }

  function handleScan() {
    setScanning(true);
    router.post(
      '/inventory/reorder-alerts/scan',
      {},
      {
        onSuccess: (page) => {
          const flash = page.props.flash as { success?: string };
          toast.success(flash?.success ?? 'Scan complete.');
          setScanning(false);
        },
        onError: () => {
          toast.error('Scan failed.');
          setScanning(false);
        },
        preserveScroll: true,
      }
    );
  }

  function acknowledge() {
    if (!ackId) return;
    router.post(
      `/inventory/reorder-alerts/${ackId}/acknowledge`,
      { notes: ackNotes },
      {
        onSuccess: () => {
          toast.success('Alert acknowledged.');
          setAckId(null);
          setAckNotes('');
        },
        onError: () => toast.error('Failed to acknowledge alert.'),
        preserveScroll: true,
      }
    );
  }

  function saveSettings(e: React.FormEvent) {
    e.preventDefault();
    patch('/inventory/reorder-alerts/settings', {
      onSuccess: () => {
        toast.success('Settings updated.');
        setShowSettings(false);
      },
      onError: () => toast.error('Failed to update settings.'),
      preserveScroll: true,
    });
  }

  function toggleRole(role: string) {
    const current = data.notify_roles;
    if (current.includes(role)) {
      setData(
        'notify_roles',
        current.filter((r) => r !== role)
      );
    } else {
      setData('notify_roles', [...current, role]);
    }
  }

  return (
    <AppLayout>
      <Head title="Reorder Point Alerts" />
      <div className="space-y-5 p-6">
        {/* Header */}
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1 flex items-center gap-1 text-xs text-muted-foreground">
              <Link href="/inventory" className="hover:text-foreground">
                Inventory
              </Link>
              <span>/</span>
              <span>Reorder Alerts</span>
            </div>
            <h1 className="text-2xl font-bold tracking-tight flex items-center gap-2">
              <AlertTriangle className="h-5 w-5 text-warning" />
              Reorder Point Alerts
            </h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Automated alerts when stock drops to or below reorder point. Email and in-app
              notifications.
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => setShowSettings(true)}>
              <SettingsIcon className="mr-1.5 h-4 w-4" />
              Settings
            </Button>
            <Button onClick={handleScan} disabled={scanning}>
              <RefreshCw className={`mr-1.5 h-4 w-4 ${scanning ? 'animate-spin' : ''}`} />
              {scanning ? 'Scanning...' : 'Run Scan'}
            </Button>
          </div>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-5 gap-4">
          <StatCard
            label="Open"
            value={summary.total_open}
            icon={<AlertTriangle className="h-4 w-4" />}
            accent="destructive"
          />
          <StatCard
            label="Acknowledged"
            value={summary.total_acknowledged}
            icon={<CheckCircle className="h-4 w-4" />}
            accent="warning"
          />
          <StatCard
            label="Resolved"
            value={summary.total_resolved}
            icon={<CheckCircle className="h-4 w-4" />}
            accent="success"
          />
          <StatCard
            label="Product Alerts"
            value={summary.product_alerts}
            icon={<ShoppingCart className="h-4 w-4" />}
            accent="info"
          />
          <StatCard
            label="Supply Alerts"
            value={summary.supply_alerts}
            icon={<ShoppingCart className="h-4 w-4" />}
            accent="info"
          />
        </div>

        {/* Warehouse breakdown */}
        {summary.by_warehouse.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Open Alerts by Warehouse</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-3">
              {summary.by_warehouse.map((w) => (
                <div key={w.id} className="flex items-center gap-2 rounded-lg border px-3 py-2">
                  <span className="text-sm font-medium">{w.name}</span>
                  <Badge className="bg-destructive text-white">{w.open_alerts}</Badge>
                </div>
              ))}
            </CardContent>
          </Card>
        )}

        {/* Filters */}
        <Card>
          <CardContent className="flex flex-wrap items-end gap-3 p-4">
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Status</label>
              <Select
                value={filters.status ?? 'all'}
                onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v, page: '1' })}
              >
                <SelectTrigger className="w-36">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Statuses</SelectItem>
                  <SelectItem value="OPEN">Open</SelectItem>
                  <SelectItem value="ACKNOWLEDGED">Acknowledged</SelectItem>
                  <SelectItem value="RESOLVED">Resolved</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Warehouse</label>
              <Select
                value={filters.warehouse_id ?? 'all'}
                onValueChange={(v) =>
                  applyFilters({ warehouse_id: v === 'all' ? '' : v, page: '1' })
                }
              >
                <SelectTrigger className="w-44">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Warehouses</SelectItem>
                  {warehouses.map((w) => (
                    <SelectItem key={w.id} value={String(w.id)}>
                      {w.name} ({w.code})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <label className="text-xs font-medium text-muted-foreground">Type</label>
              <Select
                value={filters.stockable_type ?? 'all'}
                onValueChange={(v) =>
                  applyFilters({ stockable_type: v === 'all' ? '' : v, page: '1' })
                }
              >
                <SelectTrigger className="w-32">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Types</SelectItem>
                  <SelectItem value="product">Products</SelectItem>
                  <SelectItem value="supply">Supplies</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>

        {/* Alerts table */}
        <Card>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Item</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Warehouse</TableHead>
                  <TableHead className="text-right">Available</TableHead>
                  <TableHead className="text-right">Reorder Pt</TableHead>
                  <TableHead className="text-right">Suggested Qty</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Acknowledged By</TableHead>
                  <TableHead>Created</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {alerts.data.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell>
                      <div className="font-medium">{row.item_name}</div>
                      <div className="text-xs text-muted-foreground">{row.item_sku}</div>
                    </TableCell>
                    <TableCell>
                      <Badge variant="outline">{row.stream}</Badge>
                    </TableCell>
                    <TableCell>{row.warehouse}</TableCell>
                    <TableCell className="text-right">
                      <span
                        className={
                          row.available_stock <= row.reorder_point
                            ? 'font-bold text-destructive'
                            : ''
                        }
                      >
                        {row.available_stock}
                      </span>
                    </TableCell>
                    <TableCell className="text-right">{row.reorder_point}</TableCell>
                    <TableCell className="text-right font-medium">
                      {row.suggested_reorder_qty}
                    </TableCell>
                    <TableCell>
                      <Badge className={statusColors[row.status] ?? 'bg-muted'}>{row.status}</Badge>
                    </TableCell>
                    <TableCell>{row.acknowledged_by ?? '—'}</TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {formatDate(row.created_at)}
                    </TableCell>
                    <TableCell className="text-right">
                      {row.status === 'OPEN' && canManage && (
                        <Button size="sm" variant="outline" onClick={() => setAckId(row.id)}>
                          <CheckCircle className="mr-1 h-3.5 w-3.5" />
                          Acknowledge
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
                {alerts.data.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={10} className="py-8 text-center text-muted-foreground">
                      No reorder point alerts found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
            <div className="border-t p-4">
              <Paginator pagination={alerts} />
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Acknowledge modal */}
      <Dialog open={!!ackId} onOpenChange={(open) => !open && setAckId(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Acknowledge Alert</DialogTitle>
            <DialogDescription>
              Mark this alert as acknowledged with optional notes.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 pt-2">
            <Textarea
              value={ackNotes}
              onChange={(e) => setAckNotes(e.target.value)}
              placeholder="Optional notes (e.g. 'Purchase order placed with supplier')"
            />
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setAckId(null)}>
                Cancel
              </Button>
              <Button onClick={acknowledge}>Acknowledge</Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* Settings modal */}
      <Dialog open={showSettings} onOpenChange={setShowSettings}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Reorder Alert Settings</DialogTitle>
            <DialogDescription>
              Configure notification channels, recipients, and scan frequency.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={saveSettings} className="space-y-4 pt-2">
            {/* Notification channels */}
            <div className="space-y-2">
              <Label>Notification Channels</Label>
              <div className="flex gap-4">
                <label className="flex items-center gap-2">
                  <Checkbox
                    checked={data.notify_in_app_enabled}
                    onCheckedChange={(v) => setData('notify_in_app_enabled', v === true)}
                  />
                  <span className="text-sm flex items-center gap-1">
                    <Bell className="h-3.5 w-3.5" /> In-App
                  </span>
                </label>
                <label className="flex items-center gap-2">
                  <Checkbox
                    checked={data.notify_email_enabled}
                    onCheckedChange={(v) => setData('notify_email_enabled', v === true)}
                  />
                  <span className="text-sm flex items-center gap-1">
                    <Mail className="h-3.5 w-3.5" /> Email
                  </span>
                </label>
              </div>
            </div>

            {/* Notify roles */}
            <div className="space-y-2">
              <Label>Notify Users with Roles</Label>
              <div className="flex flex-wrap gap-2">
                {ALL_ROLES.map((role) => (
                  <label key={role.value} className="flex items-center gap-1.5">
                    <Checkbox
                      checked={data.notify_roles.includes(role.value)}
                      onCheckedChange={() => toggleRole(role.value)}
                    />
                    <span className="text-sm">{role.label}</span>
                  </label>
                ))}
              </div>
            </div>

            {/* Additional emails */}
            <div className="space-y-1">
              <Label htmlFor="notify_emails">Additional Notification Emails</Label>
              <Input
                id="notify_emails"
                value={data.notify_emails}
                onChange={(e) => setData('notify_emails', e.target.value)}
                placeholder="buyer@company.com, manager@company.com"
              />
              <p className="text-xs text-muted-foreground">Comma-separated email addresses.</p>
            </div>

            {/* Scan frequency */}
            <div className="space-y-1">
              <Label>Scan Frequency</Label>
              <Select
                value={data.scan_frequency}
                onValueChange={(v) => setData('scan_frequency', v)}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="hourly">Hourly</SelectItem>
                  <SelectItem value="daily">Daily</SelectItem>
                  <SelectItem value="weekly">Weekly</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Reorder multiplier */}
            <div className="space-y-1">
              <Label htmlFor="reorder_multiplier">Reorder Suggestion Multiplier</Label>
              <Input
                id="reorder_multiplier"
                type="number"
                min={1}
                max={10}
                value={data.reorder_multiplier}
                onChange={(e) => setData('reorder_multiplier', e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Suggested reorder qty = reorder_point × multiplier − current_stock
              </p>
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => setShowSettings(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={processing}>
                Save Settings
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}

function StatCard({
  label,
  value,
  icon,
  accent,
}: {
  label: string;
  value: number;
  icon: React.ReactNode;
  accent: 'destructive' | 'warning' | 'success' | 'info';
}) {
  const accentClass = {
    destructive: 'bg-destructive/10 text-destructive',
    warning: 'bg-warning/10 text-warning',
    success: 'bg-success/10 text-success',
    info: 'bg-info/10 text-info',
  }[accent];

  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex items-center justify-between">
          <span className="text-xs font-medium text-muted-foreground uppercase">{label}</span>
          <span className={accentClass}>{icon}</span>
        </div>
        <div
          className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-2xl font-bold ${accentClass}`}
        >
          {value}
        </div>
      </CardContent>
    </Card>
  );
}
