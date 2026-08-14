import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CheckCircle, XCircle, CreditCard, ArrowLeft, Users } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface RunCommission {
  id: number;
  agent_id: number;
  order_id: number;
  sale_amount: number;
  commission_rate: number;
  commission_amount: number;
  status: string;
  earned_at: string | null;
  agent?: { name: string } | null;
  order?: { order_number: string } | null;
  product?: { name: string } | null;
}

interface AgentBreakdownEntry {
  agent_id: number;
  agent_name: string;
  commission_count: number;
  total_amount: number;
}

interface RunDetail {
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

interface Props {
  run: RunDetail;
  commissions: PaginatedResponse<RunCommission>;
  agentBreakdown: AgentBreakdownEntry[];
}

const statusCfg: Record<string, { label: string; color: string }> = {
  DRAFT: { label: 'Draft', color: 'bg-muted text-muted-foreground' },
  PENDING_APPROVAL: { label: 'Pending Approval', color: 'bg-warning/10 text-warning' },
  APPROVED: { label: 'Approved', color: 'bg-info/10 text-info' },
  PAID: { label: 'Paid', color: 'bg-success/10 text-success' },
  REJECTED: { label: 'Rejected', color: 'bg-destructive/10 text-destructive' },
};

const commissionStatusCfg: Record<string, { label: string; color: string }> = {
  PENDING: { label: 'Pending', color: 'bg-warning/10 text-warning' },
  APPROVED: { label: 'Approved', color: 'bg-info/10 text-info' },
  PAID: { label: 'Paid', color: 'bg-success/10 text-success' },
  REJECTED: { label: 'Rejected', color: 'bg-destructive/10 text-destructive' },
  CANCELLED: { label: 'Cancelled', color: 'bg-muted text-muted-foreground' },
};

export default function CommissionRunDetail({ run, commissions, agentBreakdown }: Props) {
  const [rejectingCommissionId, setRejectingCommissionId] = useState<number | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  const cfg = statusCfg[run.status] ?? statusCfg.DRAFT;

  const handleApprove = () => {
    router.post(`/finance/commission-automation/${run.id}/approve`, {}, { preserveScroll: true });
  };

  const handlePay = () => {
    router.post(`/finance/commission-automation/${run.id}/pay`, {}, { preserveScroll: true });
  };

  const handleRejectCommission = (commissionId: number) => {
    if (!rejectReason.trim()) return;
    router.post(
      '/finance/commission-automation/commission/reject',
      { commission_id: commissionId, reason: rejectReason },
      {
        preserveScroll: true,
        onSuccess: () => {
          setRejectingCommissionId(null);
          setRejectReason('');
        },
      }
    );
  };

  return (
    <AppLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => router.get('/finance/commission-automation')}
            >
              <ArrowLeft className="mr-1 h-4 w-4" />
              Back
            </Button>
            <div>
              <h1 className="text-xl font-bold font-display">{run.name}</h1>
              <p className="text-sm text-muted-foreground">
                {run.period_type} | {formatDate(run.period_start)} to {formatDate(run.period_end)}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Badge className={`${cfg.color} text-xs`}>{cfg.label}</Badge>
            {run.status === 'PENDING_APPROVAL' && (
              <Button size="sm" onClick={handleApprove}>
                <CheckCircle className="mr-1 h-3.5 w-3.5" />
                Approve Run
              </Button>
            )}
            {run.status === 'APPROVED' && (
              <Button size="sm" onClick={handlePay}>
                <CreditCard className="mr-1 h-3.5 w-3.5" />
                Pay Out
              </Button>
            )}
          </div>
        </div>

        {/* Summary cards */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold">{run.commission_count}</p>
              <p className="text-xs text-muted-foreground">Commissions</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-success">{formatCurrency(run.total_amount)}</p>
              <p className="text-xs text-muted-foreground">Total Amount</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-sm font-medium">{run.approver?.name ?? '—'}</p>
              <p className="text-xs text-muted-foreground">Approved By</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-sm font-medium">{run.payer?.name ?? '—'}</p>
              <p className="text-xs text-muted-foreground">Paid By</p>
            </CardContent>
          </Card>
        </div>

        {/* Agent breakdown */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base flex items-center gap-2">
              <Users className="h-4 w-4" />
              Agent Breakdown
            </CardTitle>
          </CardHeader>
          <CardContent>
            {agentBreakdown.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-4">
                No agents in this run.
              </p>
            ) : (
              <div className="space-y-2">
                {agentBreakdown.map((entry) => (
                  <div
                    key={entry.agent_id}
                    className="flex items-center justify-between p-3 rounded-lg border text-sm"
                  >
                    <div>
                      <span className="font-medium">{entry.agent_name}</span>
                      <span className="text-muted-foreground ml-2">
                        ({entry.commission_count} commissions)
                      </span>
                    </div>
                    <span className="font-semibold text-success">
                      {formatCurrency(entry.total_amount)}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Commission list */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Commissions in this Run</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-2">
              {commissions.data.map((c) => {
                const cCfg = commissionStatusCfg[c.status] ?? commissionStatusCfg.PENDING;
                return (
                  <div key={c.id} className="flex items-center gap-3 p-3 rounded-lg border text-sm">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="font-medium">{c.agent?.name ?? 'Unknown'}</span>
                        <Badge className={`${cCfg.color} text-[10px]`}>{cCfg.label}</Badge>
                      </div>
                      <div className="text-xs text-muted-foreground mt-0.5">
                        Order {c.order?.order_number ?? 'N/A'} | {c.product?.name ?? 'N/A'} | Rate:{' '}
                        {c.commission_rate > 1
                          ? formatCurrency(c.commission_rate)
                          : `${(c.commission_rate * 100).toFixed(1)}%`}
                      </div>
                      {rejectingCommissionId === c.id && (
                        <div className="flex items-center gap-1 mt-2">
                          <input
                            type="text"
                            placeholder="Rejection reason..."
                            value={rejectReason}
                            onChange={(e) => setRejectReason(e.target.value)}
                            className="border rounded px-2 py-1 text-xs w-48"
                            autoFocus
                          />
                          <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => handleRejectCommission(c.id)}
                          >
                            Confirm
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                              setRejectingCommissionId(null);
                              setRejectReason('');
                            }}
                          >
                            Cancel
                          </Button>
                        </div>
                      )}
                    </div>
                    <div className="text-right shrink-0">
                      <p className="font-semibold text-success">
                        {formatCurrency(c.commission_amount)}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        of {formatCurrency(c.sale_amount)}
                      </p>
                    </div>
                    {run.status === 'PENDING_APPROVAL' && c.status === 'PENDING' && (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setRejectingCommissionId(c.id)}
                      >
                        <XCircle className="h-3.5 w-3.5" />
                      </Button>
                    )}
                  </div>
                );
              })}
            </div>

            {/* Pagination */}
            {commissions.last_page > 1 && (
              <div className="flex justify-center gap-2 mt-4">
                {Array.from({ length: commissions.last_page }, (_, i) => i + 1).map((page) => (
                  <Button
                    key={page}
                    variant={page === commissions.current_page ? 'default' : 'outline'}
                    size="sm"
                    onClick={() => router.get(`/finance/commission-automation/${run.id}`, { page })}
                  >
                    {page}
                  </Button>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
