import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { CheckCircle, XCircle, ClipboardList, SlidersHorizontal, Settings2 } from 'lucide-react';

interface PendingPr {
  id: number;
  pr_number: string;
  requester: string;
  department: string;
  priority: string;
  estimated_total: number;
  needed_by_date: string | null;
  reason: string;
  items_count: number;
  created_at: string;
}

interface PendingAdj {
  id: number;
  item_name: string;
  item_sku: string;
  warehouse: string;
  quantity_before: number;
  quantity_after: number;
  variance: number;
  reason_code: string;
  reason_notes: string | null;
  submitted_by: string;
  created_at: string;
}

interface User {
  id: number;
  name: string;
  role: string;
}

interface Props {
  pending_prs: PendingPr[];
  pending_adjustments: PendingAdj[];
  can_approve_pr: boolean;
  can_approve_adj: boolean;
  approval_settings: Record<string, string | null>;
  all_users: User[];
}

const ROLES = ['superadmin', 'admin', 'supervisor', 'finance', 'accounting', 'warehouse'];

export default function ApprovalsIndex({
  pending_prs,
  pending_adjustments,
  can_approve_pr,
  can_approve_adj,
  approval_settings,
  all_users,
}: Props) {
  const [tab, setTab]                   = useState<'pr' | 'adj' | 'settings'>('pr');
  const [rejectTarget, setRejectTarget] = useState<{ type: 'pr' | 'adj'; id: number } | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [processing, setProcessing]     = useState(false);
  const [settings, setSettings]         = useState({ ...approval_settings });

  const totalPending = pending_prs.length + pending_adjustments.length;

  function approvePr(id: number) {
    setProcessing(true);
    router.post(`/procurement/requests/${id}/approve`, {}, {
      onFinish: () => setProcessing(false),
      preserveScroll: true,
    });
  }

  function approveAdj(id: number) {
    setProcessing(true);
    router.post(`/inventory/adjustments/${id}/approve`, {}, {
      onFinish: () => setProcessing(false),
      preserveScroll: true,
    });
  }

  function submitReject() {
    if (!rejectTarget) return;
    setProcessing(true);
    const url = rejectTarget.type === 'pr'
      ? `/procurement/requests/${rejectTarget.id}/reject`
      : `/inventory/adjustments/${rejectTarget.id}/reject`;
    router.post(url, { reason: rejectReason }, {
      onSuccess: () => { setRejectTarget(null); setRejectReason(''); },
      onFinish: () => setProcessing(false),
      preserveScroll: true,
    });
  }

  function saveSettings() {
    router.post('/approvals/settings', settings, { preserveScroll: true });
  }

  const tabs = [
    { key: 'pr',       label: 'Purchase Requests', count: pending_prs.length,        icon: ClipboardList,       show: can_approve_pr },
    { key: 'adj',      label: 'Stock Adjustments', count: pending_adjustments.length, icon: SlidersHorizontal,   show: can_approve_adj },
    { key: 'settings', label: 'Settings',           count: 0,                         icon: Settings2,            show: true },
  ] as const;

  return (
    <AppLayout>
      <Head title="Approvals" />
      <div className="space-y-6 p-6">

        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Approvals</h1>
            <p className="text-sm text-muted-foreground">
              {totalPending > 0
                ? `${totalPending} item${totalPending !== 1 ? 's' : ''} pending your approval`
                : 'No pending approvals — all clear!'}
            </p>
          </div>
        </div>

        {/* Tabs */}
        <div className="flex gap-1 border-b">
          {tabs.filter(t => t.show).map(t => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
                tab === t.key
                  ? 'border-primary text-primary'
                  : 'border-transparent text-muted-foreground hover:text-foreground'
              }`}
            >
              <t.icon className="h-4 w-4" />
              {t.label}
              {t.count > 0 && (
                <span className="ml-1 rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">
                  {t.count}
                </span>
              )}
            </button>
          ))}
        </div>

        {/* Purchase Requests tab */}
        {tab === 'pr' && (
          <Card>
            <CardContent className="p-0">
              {pending_prs.length === 0 ? (
                <div className="py-16 text-center text-muted-foreground">
                  <ClipboardList className="mx-auto mb-3 h-10 w-10 opacity-30" />
                  <p className="text-sm">No purchase requests pending approval</p>
                </div>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>PR #</TableHead>
                      <TableHead>Requested By</TableHead>
                      <TableHead>Department</TableHead>
                      <TableHead>Priority</TableHead>
                      <TableHead className="text-right">Est. Total</TableHead>
                      <TableHead>Needed By</TableHead>
                      <TableHead>Submitted</TableHead>
                      <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {pending_prs.map(pr => (
                      <TableRow key={pr.id}>
                        <TableCell>
                          <a href={`/procurement/requests/${pr.id}`} className="font-mono text-sm font-medium text-primary hover:underline">
                            {pr.pr_number}
                          </a>
                          <div className="text-xs text-muted-foreground">{pr.items_count} item(s)</div>
                        </TableCell>
                        <TableCell className="text-sm">{pr.requester}</TableCell>
                        <TableCell className="text-sm">{pr.department}</TableCell>
                        <TableCell>
                          <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                            pr.priority === 'HIGH' ? 'bg-red-100 text-red-700' :
                            pr.priority === 'MEDIUM' ? 'bg-yellow-100 text-yellow-700' :
                            'bg-gray-100 text-gray-600'
                          }`}>
                            {pr.priority}
                          </span>
                        </TableCell>
                        <TableCell className="text-right text-sm font-medium">
                          ₱{Number(pr.estimated_total).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                        </TableCell>
                        <TableCell className="text-sm">{pr.needed_by_date ?? '—'}</TableCell>
                        <TableCell className="text-xs text-muted-foreground">{pr.created_at}</TableCell>
                        <TableCell>
                          <div className="flex justify-end gap-2">
                            <Button
                              size="sm" variant="default"
                              disabled={processing}
                              onClick={() => approvePr(pr.id)}
                              className="bg-green-600 hover:bg-green-700 text-white gap-1"
                            >
                              <CheckCircle className="h-3.5 w-3.5" /> Approve
                            </Button>
                            <Button
                              size="sm" variant="destructive"
                              disabled={processing}
                              onClick={() => setRejectTarget({ type: 'pr', id: pr.id })}
                              className="gap-1"
                            >
                              <XCircle className="h-3.5 w-3.5" /> Reject
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        )}

        {/* Stock Adjustments tab */}
        {tab === 'adj' && (
          <Card>
            <CardContent className="p-0">
              {pending_adjustments.length === 0 ? (
                <div className="py-16 text-center text-muted-foreground">
                  <SlidersHorizontal className="mx-auto mb-3 h-10 w-10 opacity-30" />
                  <p className="text-sm">No stock adjustments pending approval</p>
                </div>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Item</TableHead>
                      <TableHead>Warehouse</TableHead>
                      <TableHead className="text-right">Before</TableHead>
                      <TableHead className="text-right">After</TableHead>
                      <TableHead className="text-right">Variance</TableHead>
                      <TableHead>Reason</TableHead>
                      <TableHead>Submitted By</TableHead>
                      <TableHead>When</TableHead>
                      <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {pending_adjustments.map(adj => (
                      <TableRow key={adj.id}>
                        <TableCell>
                          <div className="font-medium text-sm">{adj.item_name}</div>
                          <div className="font-mono text-xs text-muted-foreground">{adj.item_sku}</div>
                        </TableCell>
                        <TableCell className="text-sm">{adj.warehouse}</TableCell>
                        <TableCell className="text-right text-sm">{adj.quantity_before}</TableCell>
                        <TableCell className="text-right text-sm">{adj.quantity_after}</TableCell>
                        <TableCell className={`text-right font-medium text-sm ${adj.variance > 0 ? 'text-green-600' : adj.variance < 0 ? 'text-red-600' : ''}`}>
                          {adj.variance > 0 ? `+${adj.variance}` : adj.variance}
                        </TableCell>
                        <TableCell>
                          <div className="text-xs font-medium">{adj.reason_code.replace(/_/g, ' ')}</div>
                          {adj.reason_notes && <div className="text-xs text-muted-foreground line-clamp-1">{adj.reason_notes}</div>}
                        </TableCell>
                        <TableCell className="text-sm">{adj.submitted_by}</TableCell>
                        <TableCell className="text-xs text-muted-foreground">{adj.created_at}</TableCell>
                        <TableCell>
                          <div className="flex justify-end gap-2">
                            <Button
                              size="sm" variant="default"
                              disabled={processing}
                              onClick={() => approveAdj(adj.id)}
                              className="bg-green-600 hover:bg-green-700 text-white gap-1"
                            >
                              <CheckCircle className="h-3.5 w-3.5" /> Approve
                            </Button>
                            <Button
                              size="sm" variant="destructive"
                              disabled={processing}
                              onClick={() => setRejectTarget({ type: 'adj', id: adj.id })}
                              className="gap-1"
                            >
                              <XCircle className="h-3.5 w-3.5" /> Reject
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        )}

        {/* Settings tab */}
        {tab === 'settings' && (
          <Card>
            <CardContent className="space-y-6 p-6">
              <h2 className="text-base font-semibold">Approval Configuration</h2>
              <p className="text-sm text-muted-foreground">Configure which roles and designated users can approve each document type. Role-based approvals notify all users with that role; a designated user is additionally notified regardless of role.</p>

              {([
                { label: 'Purchase Requests', rolesKey: 'pr_approver_roles', userKey: 'pr_approver_user_id' },
                { label: 'Purchase Orders', rolesKey: 'po_approver_roles', userKey: 'po_approver_user_id' },
                { label: 'Stock Adjustments', rolesKey: 'adjustment_approver_roles', userKey: 'adjustment_approver_user_id' },
              ] as const).map(({ label, rolesKey, userKey }) => (
                <div key={rolesKey} className="rounded-lg border p-4 space-y-4">
                  <h3 className="text-sm font-semibold">{label}</h3>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-1.5">
                      <Label>Approver Roles <span className="text-muted-foreground">(comma-separated)</span></Label>
                      <input
                        type="text"
                        className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                        value={settings[rolesKey] ?? ''}
                        onChange={e => setSettings(s => ({ ...s, [rolesKey]: e.target.value }))}
                        placeholder="e.g. admin,supervisor"
                      />
                      <p className="text-xs text-muted-foreground">Available: {ROLES.join(', ')}</p>
                    </div>
                    <div className="space-y-1.5">
                      <Label>Designated Approver <span className="text-muted-foreground">(optional specific user)</span></Label>
                      <Select
                        value={settings[userKey] ?? '__none__'}
                        onValueChange={v => setSettings(s => ({ ...s, [userKey]: v === '__none__' ? null : v }))}
                      >
                        <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="__none__">— None —</SelectItem>
                          {all_users.map(u => (
                            <SelectItem key={u.id} value={String(u.id)}>
                              {u.name} <span className="text-muted-foreground text-xs">({u.role})</span>
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </div>
                </div>
              ))}

              <Button onClick={saveSettings}>Save Settings</Button>
            </CardContent>
          </Card>
        )}

        {/* Reject Dialog */}
        <Dialog open={!!rejectTarget} onOpenChange={o => !o && setRejectTarget(null)}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle>Reject {rejectTarget?.type === 'pr' ? 'Purchase Request' : 'Stock Adjustment'}</DialogTitle>
              <DialogDescription>Provide a reason for rejection. The submitter will be notified.</DialogDescription>
            </DialogHeader>
            <div className="space-y-3">
              <Label>Reason *</Label>
              <Textarea
                rows={3}
                value={rejectReason}
                onChange={e => setRejectReason(e.target.value)}
                placeholder="Explain why this is being rejected..."
                autoFocus
              />
              <div className="flex justify-end gap-2">
                <Button variant="outline" onClick={() => setRejectTarget(null)}>Cancel</Button>
                <Button variant="destructive" disabled={!rejectReason.trim() || processing} onClick={submitReject}>
                  Confirm Rejection
                </Button>
              </div>
            </div>
          </DialogContent>
        </Dialog>

      </div>
    </AppLayout>
  );
}
