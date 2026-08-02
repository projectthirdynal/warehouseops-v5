import { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { ArrowLeft, CheckCircle, XCircle, Banknote } from 'lucide-react';
import { formatDate, formatDateTime } from '@/lib/utils';
import type { Claim } from '@/types';

interface Props {
  claim: Claim;
}

const STATUS_COLORS: Record<string, string> = {
  DRAFT: 'bg-muted text-muted-foreground',
  FILED: 'bg-info/10 text-info',
  UNDER_REVIEW: 'bg-warning/10 text-warning',
  APPROVED: 'bg-success/10 text-success',
  REJECTED: 'bg-destructive/10 text-destructive',
  SETTLED: 'bg-success/10 text-success',
};

const STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Draft',
  FILED: 'Filed',
  UNDER_REVIEW: 'Under Review',
  APPROVED: 'Approved',
  REJECTED: 'Rejected',
  SETTLED: 'Settled',
};

const TYPE_LABELS: Record<string, string> = {
  LOST: 'Lost Parcel',
  DAMAGED: 'Damaged Parcel',
  BEYOND_SLA: 'Beyond SLA',
};

export default function ClaimShow({ claim }: Props) {
  const [approveOpen, setApproveOpen] = useState(false);
  const [rejectOpen, setRejectOpen] = useState(false);

  const approveForm = useForm({
    approved_amount: claim.claim_amount?.toString() ?? '',
    jnt_reference_number: '',
    resolution_notes: '',
  });

  const rejectForm = useForm({
    resolution_notes: '',
  });

  function submitApprove(e: React.FormEvent) {
    e.preventDefault();
    approveForm.post(`/waybills/claims/${claim.id}/approve`, {
      onSuccess: () => setApproveOpen(false),
    });
  }

  function submitReject(e: React.FormEvent) {
    e.preventDefault();
    rejectForm.post(`/waybills/claims/${claim.id}/reject`, {
      onSuccess: () => setRejectOpen(false),
    });
  }

  function fileClai() {
    router.post(`/waybills/claims/${claim.id}/file`);
  }

  function settle() {
    router.post(`/waybills/claims/${claim.id}/settle`);
  }

  return (
    <AppLayout>
      <Head title={`Claim ${claim.claim_number}`} />

      <div className="max-w-3xl space-y-4 p-6">
        {/* Header */}
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <Link href="/waybills/claims">
              <Button variant="ghost" size="sm">
                <ArrowLeft className="h-4 w-4" />
              </Button>
            </Link>
            <div>
              <h1 className="font-mono text-xl font-bold">{claim.claim_number}</h1>
              <div className="mt-1 flex items-center gap-2">
                <span
                  className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[claim.status]}`}
                >
                  {STATUS_LABELS[claim.status]}
                </span>
                <span className="text-sm text-muted-foreground">
                  {TYPE_LABELS[claim.type] ?? claim.type}
                </span>
              </div>
            </div>
          </div>

          {/* Actions */}
          <div className="flex gap-2">
            {claim.status === 'DRAFT' && (
              <Button size="sm" onClick={fileClai}>
                Submit for Review
              </Button>
            )}
            {(claim.status === 'FILED' || claim.status === 'UNDER_REVIEW') && (
              <>
                <Button
                  size="sm"
                  variant="outline"
                  className="text-destructive border-destructive/20"
                  onClick={() => setRejectOpen(true)}
                >
                  <XCircle className="mr-1 h-4 w-4" />
                  Reject
                </Button>
                <Button size="sm" onClick={() => setApproveOpen(true)}>
                  <CheckCircle className="mr-1 h-4 w-4" />
                  Approve
                </Button>
              </>
            )}
            {claim.status === 'APPROVED' && (
              <Button size="sm" variant="outline" onClick={settle}>
                <Banknote className="mr-1 h-4 w-4" />
                Mark Settled
              </Button>
            )}
          </div>
        </div>

        {/* Claim info */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Claim Information</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-2 gap-4 text-sm">
            <div>
              <p className="text-muted-foreground">Waybill</p>
              <Link
                href={`/waybills/${claim.waybill_id}`}
                className="font-mono font-medium hover:underline"
              >
                {claim.waybill?.waybill_number ?? `#${claim.waybill_id}`}
              </Link>
            </div>
            <div>
              <p className="text-muted-foreground">Receiver</p>
              <p className="font-medium">{claim.waybill?.receiver_name ?? '—'}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Claim Amount</p>
              <p className="text-lg font-bold">
                ₱{Number(claim.claim_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </p>
            </div>
            {claim.approved_amount != null && (
              <div>
                <p className="text-muted-foreground">Approved Amount</p>
                <p className="text-lg font-bold text-success">
                  ₱
                  {Number(claim.approved_amount).toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                  })}
                </p>
              </div>
            )}
            <div>
              <p className="text-muted-foreground">Filed By</p>
              <p>{claim.filed_by_user?.name ?? '—'}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Filed Date</p>
              <p>{claim.filed_at ? formatDateTime(claim.filed_at) : 'Not yet filed'}</p>
            </div>
            {claim.jnt_reference_number && (
              <div>
                <p className="text-muted-foreground">J&T Reference #</p>
                <p className="font-mono font-medium">{claim.jnt_reference_number}</p>
              </div>
            )}
            {claim.reviewed_by_user && (
              <div>
                <p className="text-muted-foreground">Reviewed By</p>
                <p>{claim.reviewed_by_user.name}</p>
              </div>
            )}
            {claim.resolved_at && (
              <div>
                <p className="text-muted-foreground">Resolved</p>
                <p>{formatDate(claim.resolved_at)}</p>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Description */}
        {claim.description && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Description</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-sm whitespace-pre-wrap">{claim.description}</p>
            </CardContent>
          </Card>
        )}

        {/* Resolution notes */}
        {claim.resolution_notes && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Resolution Notes</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-sm whitespace-pre-wrap">{claim.resolution_notes}</p>
            </CardContent>
          </Card>
        )}
      </div>

      {/* Approve dialog */}
      <Dialog open={approveOpen} onOpenChange={setApproveOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Approve Claim</DialogTitle>
            <DialogDescription>Confirm the approved amount for this claim.</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitApprove} className="space-y-4">
            <div className="space-y-1">
              <Label>Approved Amount (₱)</Label>
              <Input
                type="number"
                step="0.01"
                min="0"
                value={approveForm.data.approved_amount}
                onChange={(e) => approveForm.setData('approved_amount', e.target.value)}
              />
              {approveForm.errors.approved_amount && (
                <p className="text-sm text-destructive">{approveForm.errors.approved_amount}</p>
              )}
            </div>
            <div className="space-y-1">
              <Label>
                J&T Reference # <span className="text-muted-foreground">(optional)</span>
              </Label>
              <Input
                value={approveForm.data.jnt_reference_number}
                onChange={(e) => approveForm.setData('jnt_reference_number', e.target.value)}
                placeholder="J&T claim reference number"
              />
            </div>
            <div className="space-y-1">
              <Label>
                Notes <span className="text-muted-foreground">(optional)</span>
              </Label>
              <Textarea
                value={approveForm.data.resolution_notes}
                onChange={(e) => approveForm.setData('resolution_notes', e.target.value)}
                rows={3}
              />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setApproveOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={approveForm.processing}>
                Approve Claim
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Reject dialog */}
      <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reject Claim</DialogTitle>
            <DialogDescription>Provide a reason for rejecting this claim.</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitReject} className="space-y-4">
            <div className="space-y-1">
              <Label>Reason for Rejection</Label>
              <Textarea
                value={rejectForm.data.resolution_notes}
                onChange={(e) => rejectForm.setData('resolution_notes', e.target.value)}
                placeholder="Explain why this claim is being rejected..."
                rows={4}
                required
              />
              {rejectForm.errors.resolution_notes && (
                <p className="text-sm text-destructive">{rejectForm.errors.resolution_notes}</p>
              )}
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setRejectOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" variant="destructive" disabled={rejectForm.processing}>
                Reject Claim
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
