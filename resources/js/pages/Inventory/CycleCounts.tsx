import { useState } from 'react';
import { toast } from 'sonner';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
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
  ClipboardList,
  Plus,
  CheckCircle2,
  Clock,
  Target,
  Settings as SettingsIcon,
  BarChart3,
} from 'lucide-react';
import { formatDate } from '@/lib/utils';

interface Warehouse {
  id: number;
  name: string;
  code: string;
}

interface SessionRow {
  id: number;
  name: string;
  warehouse: string;
  warehouse_id: number;
  status: string;
  auto_generated: boolean;
  total_items: number;
  counted_items: number;
  variance_items: number;
  started_at: string | null;
  finalized_at: string | null;
  created_at: string;
}

interface Settings {
  auto_generate_enabled: boolean;
  frequency: string;
  sample_size: number;
  auto_create_adjustments: boolean;
}

interface Dashboard {
  sessions: SessionRow[];
  summary: {
    open_sessions: number;
    finalized_sessions: number;
    total_variance_items: number;
    accuracy_rate: number;
  };
  settings: Settings;
  warehouses: Warehouse[];
}

function StatusBadge({ status }: { status: string }) {
  const map: Record<string, string> = {
    OPEN: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
    COUNTING: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    FINALIZED: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
    CANCELLED: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  };
  return (
    <Badge className={map[status] ?? 'bg-muted text-muted-foreground'} variant="secondary">
      {status}
    </Badge>
  );
}

export default function CycleCounts({ dashboard }: { dashboard: Dashboard }) {
  const { sessions, summary, settings, warehouses } = dashboard;
  const [newOpen, setNewOpen] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [warehouseId, setWarehouseId] = useState(String(warehouses[0]?.id ?? ''));
  const [sampleSize, setSampleSize] = useState(String(settings.sample_size));
  const [creating, setCreating] = useState(false);

  const [autoGenerate, setAutoGenerate] = useState(settings.auto_generate_enabled);
  const [frequency, setFrequency] = useState(settings.frequency);
  const [defaultSampleSize, setDefaultSampleSize] = useState(String(settings.sample_size));
  const [autoAdjustments, setAutoAdjustments] = useState(settings.auto_create_adjustments);
  const [savingSettings, setSavingSettings] = useState(false);

  function createSession() {
    if (!warehouseId) {
      toast.error('Select a warehouse.');
      return;
    }
    setCreating(true);
    router.post(
      '/inventory/cycle-counts',
      { warehouse_id: warehouseId, sample_size: sampleSize },
      {
        onSuccess: () => {
          toast.success('Cycle count session created.');
          setNewOpen(false);
        },
        onError: () => toast.error('Failed to create session.'),
        onFinish: () => setCreating(false),
        preserveScroll: true,
      }
    );
  }

  function saveSettings() {
    setSavingSettings(true);
    router.patch(
      '/inventory/cycle-counts/settings',
      {
        auto_generate_enabled: autoGenerate,
        frequency,
        sample_size: defaultSampleSize,
        auto_create_adjustments: autoAdjustments,
      },
      {
        onSuccess: () => {
          toast.success('Settings updated.');
          setSettingsOpen(false);
        },
        onError: () => toast.error('Failed to update settings.'),
        onFinish: () => setSavingSettings(false),
        preserveScroll: true,
      }
    );
  }

  return (
    <AppLayout>
      <Head title="Cycle Counts" />
      <div className="space-y-6 p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Cycle Count Module</h1>
            <p className="text-sm text-muted-foreground">
              Scheduled inventory counts with automated variance reporting
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/inventory/cycle-counts/report">
              <Button variant="outline">
                <BarChart3 className="mr-1.5 h-4 w-4" />
                Variance Report
              </Button>
            </Link>
            <Button variant="outline" onClick={() => setSettingsOpen(true)}>
              <SettingsIcon className="mr-1.5 h-4 w-4" />
              Settings
            </Button>
            <Button onClick={() => setNewOpen(true)}>
              <Plus className="mr-1.5 h-4 w-4" />
              New Count
            </Button>
          </div>
        </div>

        {/* Summary cards */}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <Clock className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Open / In Progress</p>
              </div>
              <p className="mt-1 text-2xl font-bold">{summary.open_sessions}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <CheckCircle2 className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Finalized</p>
              </div>
              <p className="mt-1 text-2xl font-bold">{summary.finalized_sessions}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <ClipboardList className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Variance Items</p>
              </div>
              <p className="mt-1 text-2xl font-bold">{summary.total_variance_items}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-2">
                <Target className="h-4 w-4 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Accuracy Rate</p>
              </div>
              <p className="mt-1 text-2xl font-bold">{summary.accuracy_rate}%</p>
            </CardContent>
          </Card>
        </div>

        {/* Sessions table */}
        <Card>
          <CardHeader>
            <CardTitle>Cycle Count Sessions</CardTitle>
          </CardHeader>
          <CardContent>
            {sessions.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">
                No cycle count sessions yet. Create one to get started.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Session</TableHead>
                      <TableHead>Warehouse</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead className="text-right">Progress</TableHead>
                      <TableHead className="text-right">Variances</TableHead>
                      <TableHead>Created</TableHead>
                      <TableHead />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {sessions.map((s) => (
                      <TableRow key={s.id}>
                        <TableCell>
                          <div className="text-sm font-medium">{s.name}</div>
                          {s.auto_generated && (
                            <span className="text-[11px] text-muted-foreground">Scheduled</span>
                          )}
                        </TableCell>
                        <TableCell className="text-sm">{s.warehouse}</TableCell>
                        <TableCell>
                          <StatusBadge status={s.status} />
                        </TableCell>
                        <TableCell className="text-right text-sm">
                          {s.counted_items}/{s.total_items}
                        </TableCell>
                        <TableCell className="text-right">
                          {s.variance_items > 0 ? (
                            <Badge variant="destructive">{s.variance_items}</Badge>
                          ) : (
                            <span className="text-muted-foreground">0</span>
                          )}
                        </TableCell>
                        <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                          {formatDate(s.created_at)}
                        </TableCell>
                        <TableCell className="text-right">
                          <Link href={`/inventory/cycle-counts/${s.id}`}>
                            <Button size="sm" variant="outline">
                              {s.status === 'FINALIZED' ? 'View' : 'Count'}
                            </Button>
                          </Link>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* New Session Dialog */}
      <Dialog open={newOpen} onOpenChange={setNewOpen}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>New Cycle Count</DialogTitle>
            <DialogDescription>
              A random sample of in-stock products from the selected warehouse will be added for
              counting.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-1.5">
              <Label>Warehouse</Label>
              <Select value={warehouseId} onValueChange={setWarehouseId}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {warehouses.map((wh) => (
                    <SelectItem key={wh.id} value={String(wh.id)}>
                      {wh.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label>Sample Size</Label>
              <Input
                type="number"
                min={1}
                max={200}
                value={sampleSize}
                onChange={(e) => setSampleSize(e.target.value)}
              />
            </div>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={() => setNewOpen(false)}>
              Cancel
            </Button>
            <Button onClick={createSession} disabled={creating}>
              {creating ? 'Creating…' : 'Create Session'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Settings Dialog */}
      <Dialog open={settingsOpen} onOpenChange={setSettingsOpen}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>Cycle Count Settings</DialogTitle>
            <DialogDescription>
              Configure scheduled generation and variance handling.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <Label className="text-sm">Auto-generate scheduled counts</Label>
              <Switch checked={autoGenerate} onCheckedChange={setAutoGenerate} />
            </div>
            <div className="space-y-1.5">
              <Label>Frequency</Label>
              <Select value={frequency} onValueChange={setFrequency}>
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
            <div className="space-y-1.5">
              <Label>Default Sample Size</Label>
              <Input
                type="number"
                min={1}
                max={200}
                value={defaultSampleSize}
                onChange={(e) => setDefaultSampleSize(e.target.value)}
              />
            </div>
            <div className="flex items-center justify-between">
              <Label className="text-sm">Auto-create adjustments for variances</Label>
              <Switch checked={autoAdjustments} onCheckedChange={setAutoAdjustments} />
            </div>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={() => setSettingsOpen(false)}>
              Cancel
            </Button>
            <Button onClick={saveSettings} disabled={savingSettings}>
              {savingSettings ? 'Saving…' : 'Save Settings'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
