import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  CalendarClock,
  CheckCircle,
  XCircle,
  CreditCard,
  Plus,
  Settings,
  AlertCircle,
} from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface CommissionRun {
  id: number;
  name: string;
  period_type: string;
  period_start: string;
  period_end: string;
  status: string;
  commission_count: number;
  total_amount: number;
  approved_at: string | null;
  paid_at: string | null;
  rejected_at: string | null;
  rejection_reason: string | null;
  created_at: string;
  creator?: { name: string } | null;
  approver?: { name: string } | null;
  payer?: { name: string } | null;
}

interface RunStats {
  total_runs: number;
  pending_approval: number;
  approved: number;
  paid: number;
  rejected: number;
  total_pending_amount: number;
  total_approved_amount: number;
  total_paid_amount: number;
  unassigned_pending: number;
}

interface AutomationSettings {
  [key: string]: string | number | boolean;
  frequency: string;
  auto_generate_enabled: boolean;
  auto_approve_threshold: number;
  min_commission_amount: number;
  require_approval: boolean;
}

interface Props {
  runs: PaginatedResponse<CommissionRun>;
  stats: RunStats;
  settings: AutomationSettings;
  filters: { status?: string };
}

const statusCfg: Record<string, { label: string; color: string }> = {
  DRAFT: { label: 'Draft', color: 'bg-muted text-muted-foreground' },
  PENDING_APPROVAL: { label: 'Pending Approval', color: 'bg-warning/10 text-warning' },
  APPROVED: { label: 'Approved', color: 'bg-info/10 text-info' },
  PAID: { label: 'Paid', color: 'bg-success/10 text-success' },
  REJECTED: { label: 'Rejected', color: 'bg-destructive/10 text-destructive' },
};

export default function CommissionAutomation({ runs, stats, settings, filters }: Props) {
  const [showCreateForm, setShowCreateForm] = useState(false);
  const [showSettings, setShowSettings] = useState(false);
  const [createForm, setCreateForm] = useState({
    period_type: 'MONTHLY',
    period_start: '',
    period_end: '',
  });
  const [settingsForm, setSettingsForm] = useState(settings);
  const [rejectingRunId, setRejectingRunId] = useState<number | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  const handleCreateRun = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      '/finance/commission-automation',
      {
        period_type: createForm.period_type,
        period_start: createForm.period_start || undefined,
        period_end: createForm.period_end || undefined,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setShowCreateForm(false);
          setCreateForm({ period_type: 'MONTHLY', period_start: '', period_end: '' });
        },
      }
    );
  };

  const handleApprove = (runId: number) => {
    router.post(`/finance/commission-automation/${runId}/approve`, {}, { preserveScroll: true });
  };

  const handleReject = (runId: number) => {
    if (!rejectReason.trim()) return;
    router.post(
      `/finance/commission-automation/${runId}/reject`,
      { reason: rejectReason },
      {
        preserveScroll: true,
        onSuccess: () => {
          setRejectingRunId(null);
          setRejectReason('');
        },
      }
    );
  };

  const handlePay = (runId: number) => {
    router.post(`/finance/commission-automation/${runId}/pay`, {}, { preserveScroll: true });
  };

  const handleSaveSettings = (e: React.FormEvent) => {
    e.preventDefault();
    router.patch('/finance/commission-automation/settings', settingsForm, {
      preserveScroll: true,
      onSuccess: () => setShowSettings(false),
    });
  };

  return (
    <AppLayout>
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold font-display">Commission Automation</h1>
            <p className="text-sm text-muted-foreground">
              Scheduled commission runs with approval before payout
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={() => setShowSettings(!showSettings)}>
              <Settings className="mr-1 h-3.5 w-3.5" />
              Settings
            </Button>
            <Button size="sm" onClick={() => setShowCreateForm(!showCreateForm)}>
              <Plus className="mr-1 h-3.5 w-3.5" />
              New Run
            </Button>
          </div>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-warning">
                {formatCurrency(stats.total_pending_amount)}
              </p>
              <p className="text-xs text-muted-foreground">Pending</p>
              <p className="text-xs text-muted-foreground mt-0.5">
                {stats.unassigned_pending} unassigned
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-info">
                {formatCurrency(stats.total_approved_amount)}
              </p>
              <p className="text-xs text-muted-foreground">Approved (unpaid)</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-success">
                {formatCurrency(stats.total_paid_amount)}
              </p>
              <p className="text-xs text-muted-foreground">Total Paid</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold">{stats.total_runs}</p>
              <p className="text-xs text-muted-foreground">Total Runs</p>
              <p className="text-xs text-muted-foreground mt-0.5">
                {stats.pending_approval} pending approval
              </p>
            </CardContent>
          </Card>
        </div>

        {/* Settings Panel */}
        {showSettings && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Automation Settings</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSaveSettings} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="text-sm font-medium mb-1 block">Run Frequency</label>
                    <select
                      value={settingsForm.frequency}
                      onChange={(e) =>
                        setSettingsForm((p) => ({ ...p, frequency: e.target.value }))
                      }
                      className="w-full border rounded-lg px-3 py-2 text-sm"
                    >
                      <option value="DAILY">Daily</option>
                      <option value="WEEKLY">Weekly</option>
                      <option value="MONTHLY">Monthly</option>
                    </select>
                  </div>
                  <div>
                    <label className="text-sm font-medium mb-1 block">
                      Auto-Approve Threshold (0 = disabled)
                    </label>
                    <input
                      type="number"
                      step="0.01"
                      value={settingsForm.auto_approve_threshold}
                      onChange={(e) =>
                        setSettingsForm((p) => ({
                          ...p,
                          auto_approve_threshold: parseFloat(e.target.value) || 0,
                        }))
                      }
                      className="w-full border rounded-lg px-3 py-2 text-sm"
                    />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="text-sm font-medium mb-1 block">Min Commission Amount</label>
                    <input
                      type="number"
                      step="0.01"
                      value={settingsForm.min_commission_amount}
                      onChange={(e) =>
                        setSettingsForm((p) => ({
                          ...p,
                          min_commission_amount: parseFloat(e.target.value) || 0,
                        }))
                      }
                      className="w-full border rounded-lg px-3 py-2 text-sm"
                    />
                  </div>
                  <div className="flex items-center gap-6 pt-6">
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={settingsForm.auto_generate_enabled}
                        onChange={(e) =>
                          setSettingsForm((p) => ({
                            ...p,
                            auto_generate_enabled: e.target.checked,
                          }))
                        }
                        className="rounded"
                      />
                      Auto-generate runs
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={settingsForm.require_approval}
                        onChange={(e) =>
                          setSettingsForm((p) => ({
                            ...p,
                            require_approval: e.target.checked,
                          }))
                        }
                        className="rounded"
                      />
                      Require approval
                    </label>
                  </div>
                </div>
                <div className="flex justify-end gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setShowSettings(false)}
                  >
                    Cancel
                  </Button>
                  <Button type="submit" size="sm">
                    Save Settings
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}

        {/* Create Run Form */}
        {showCreateForm && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Create Commission Run</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleCreateRun} className="grid grid-cols-3 gap-3">
                <select
                  value={createForm.period_type}
                  onChange={(e) => setCreateForm((p) => ({ ...p, period_type: e.target.value }))}
                  className="border rounded-lg px-3 py-2 text-sm"
                >
                  <option value="DAILY">Daily</option>
                  <option value="WEEKLY">Weekly</option>
                  <option value="MONTHLY">Monthly</option>
                  <option value="MANUAL">Manual (custom range)</option>
                </select>
                <input
                  type="date"
                  placeholder="Period start (optional)"
                  value={createForm.period_start}
                  onChange={(e) => setCreateForm((p) => ({ ...p, period_start: e.target.value }))}
                  className="border rounded-lg px-3 py-2 text-sm"
                />
                <input
                  type="date"
                  placeholder="Period end (optional)"
                  value={createForm.period_end}
                  onChange={(e) => setCreateForm((p) => ({ ...p, period_end: e.target.value }))}
                  className="border rounded-lg px-3 py-2 text-sm"
                />
                <div className="col-span-3 flex justify-end gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setShowCreateForm(false)}
                  >
                    Cancel
                  </Button>
                  <Button type="submit" size="sm">
                    Create Run
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}

        {/* Filters */}
        <div className="flex items-center gap-3">
          <select
            value={filters.status || ''}
            onChange={(e) =>
              router.get(
                '/finance/commission-automation',
                { status: e.target.value || undefined },
                { preserveState: true }
              )
            }
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Status</option>
            <option value="PENDING_APPROVAL">Pending Approval</option>
            <option value="APPROVED">Approved</option>
            <option value="PAID">Paid</option>
            <option value="REJECTED">Rejected</option>
          </select>
        </div>

        {/* Run list */}
        <div className="space-y-3">
          {runs.data.length === 0 ? (
            <Card>
              <CardContent className="py-12 text-center text-muted-foreground">
                <CalendarClock className="h-10 w-10 mx-auto mb-3 opacity-30" />
                <p>No commission runs yet. Create one to group pending commissions for approval.</p>
              </CardContent>
            </Card>
          ) : (
            runs.data.map((run) => {
              const cfg = statusCfg[run.status] ?? statusCfg.DRAFT;
              return (
                <Card key={run.id}>
                  <CardContent className="p-4">
                    <div className="flex items-center justify-between gap-4">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <a
                            href={`/finance/commission-automation/${run.id}`}
                            className="font-semibold hover:underline"
                          >
                            {run.name}
                          </a>
                          <Badge className={`${cfg.color} text-[10px]`}>{cfg.label}</Badge>
                        </div>
                        <div className="text-xs text-muted-foreground mt-1">
                          {run.period_type} | {formatDate(run.period_start)} to{' '}
                          {formatDate(run.period_end)} | {run.commission_count} commissions
                          {run.creator && ` | by ${run.creator.name}`}
                          {run.approver && ` | approved by ${run.approver.name}`}
                          {run.payer && ` | paid by ${run.payer.name}`}
                        </div>
                        {run.rejection_reason && (
                          <div className="text-xs text-destructive mt-1 flex items-center gap-1">
                            <AlertCircle className="h-3 w-3" />
                            {run.rejection_reason}
                          </div>
                        )}
                      </div>
                      <div className="text-right shrink-0">
                        <p className="font-semibold text-success">
                          {formatCurrency(run.total_amount)}
                        </p>
                      </div>
                      {/* Action buttons */}
                      <div className="flex gap-1 shrink-0">
                        {run.status === 'PENDING_APPROVAL' && (
                          <>
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleApprove(run.id)}
                            >
                              <CheckCircle className="mr-1 h-3.5 w-3.5" />
                              Approve
                            </Button>
                            {rejectingRunId === run.id ? (
                              <div className="flex items-center gap-1">
                                <input
                                  type="text"
                                  placeholder="Rejection reason..."
                                  value={rejectReason}
                                  onChange={(e) => setRejectReason(e.target.value)}
                                  className="border rounded px-2 py-1 text-xs w-40"
                                  autoFocus
                                />
                                <Button
                                  size="sm"
                                  variant="destructive"
                                  onClick={() => handleReject(run.id)}
                                >
                                  Confirm
                                </Button>
                                <Button
                                  size="sm"
                                  variant="ghost"
                                  onClick={() => {
                                    setRejectingRunId(null);
                                    setRejectReason('');
                                  }}
                                >
                                  Cancel
                                </Button>
                              </div>
                            ) : (
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setRejectingRunId(run.id)}
                              >
                                <XCircle className="mr-1 h-3.5 w-3.5" />
                                Reject
                              </Button>
                            )}
                          </>
                        )}
                        {run.status === 'APPROVED' && (
                          <Button size="sm" onClick={() => handlePay(run.id)}>
                            <CreditCard className="mr-1 h-3.5 w-3.5" />
                            Pay Out
                          </Button>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })
          )}
        </div>

        {/* Pagination */}
        {runs.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: runs.last_page }, (_, i) => i + 1).map((page) => (
              <Button
                key={page}
                variant={page === runs.current_page ? 'default' : 'outline'}
                size="sm"
                onClick={() => router.get('/finance/commission-automation', { ...filters, page })}
              >
                {page}
              </Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
