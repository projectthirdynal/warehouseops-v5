import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, XCircle } from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface PoolRequest {
  id: number;
  request_number: string;
  brand_name: string;
  product_name: string | null;
  business_region: string | null;
  province: string | null;
  city: string | null;
  lead_age_from: number;
  lead_age_to: number | null;
  requested_quantity: number;
  available_quantity_at_request: number;
  distribution_method: string;
  status: string;
  notes: string | null;
  created_at: string;
  requested_by: { id: number; name: string };
  pool: { id: number; pool_number: string } | null;
}

interface Props {
  poolRequest: PoolRequest;
  currentAvailable: number;
  canFulfill: boolean;
}

export default function PoolApprovalShow({ poolRequest, currentAvailable, canFulfill }: Props) {
  // If we can't fulfill the full request, default to the available count
  const initialQty = canFulfill
    ? poolRequest.requested_quantity
    : Math.min(currentAvailable, poolRequest.requested_quantity);

  const [approvedQty, setApprovedQty] = useState(initialQty);
  const [approveNotes, setApproveNotes] = useState('');
  const [rejectReason, setRejectReason] = useState('');
  const [showReject, setShowReject] = useState(false);

  const approveForm = useForm({
    approved_quantity: initialQty,
    notes: '',
  });

  const rejectForm = useForm({
    rejection_reason: '',
  });

  const handleApprove = (e: React.FormEvent) => {
    e.preventDefault();
    approveForm.post(`/telesales/pool-approvals/${poolRequest.id}/approve`, {
      data: { approved_quantity: approvedQty, notes: approveNotes },
    });
  };

  const handleReject = (e: React.FormEvent) => {
    e.preventDefault();
    rejectForm.post(`/telesales/pool-approvals/${poolRequest.id}/reject`, {
      data: { rejection_reason: rejectReason },
    });
  };

  const fmt = (d: string) =>
    new Date(d).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

  return (
    <TelesalesLayout>
      <Head title={`Approve ${poolRequest.request_number}`} />
      <div className="space-y-6 p-6 max-w-4xl">
        <div className="flex items-center gap-4">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => router.visit('/telesales/pool-approvals')}
          >
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back to Queue
          </Button>
          <div>
            <h1 className="text-2xl font-bold tracking-tight font-mono">
              {poolRequest.request_number}
            </h1>
            <Badge className="mt-1" variant="secondary">
              {poolRequest.status.replace(/_/g, ' ')}
            </Badge>
          </div>
        </div>

        {/* Availability comparison */}
        <Card>
          <CardHeader>
            <CardTitle>Availability Check</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-3 gap-4 text-center">
              <div>
                <p className="text-xs text-muted-foreground mb-1">At Request Time</p>
                <p className="text-2xl font-bold">
                  {poolRequest.available_quantity_at_request.toLocaleString()}
                </p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground mb-1">Currently Available</p>
                <p
                  className={`text-2xl font-bold ${canFulfill ? 'text-green-600' : 'text-orange-600'}`}
                >
                  {currentAvailable.toLocaleString()}
                </p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground mb-1">Requested</p>
                <p className="text-2xl font-bold">
                  {poolRequest.requested_quantity.toLocaleString()}
                </p>
              </div>
            </div>
            <div className="mt-4 text-center">
              <Badge
                variant={canFulfill ? 'default' : 'destructive'}
                className={canFulfill ? 'bg-green-100 text-green-800' : ''}
              >
                {canFulfill ? 'CAN FULFILL — YES' : 'CANNOT FULFILL — SHORTFALL'}
              </Badge>
            </div>
          </CardContent>
        </Card>

        <div className="grid gap-6 md:grid-cols-2">
          {/* Request details */}
          <Card>
            <CardHeader>
              <CardTitle>Request Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <Row label="Brand" value={poolRequest.brand_name} />
              <Row label="Product" value={poolRequest.product_name || 'All'} />
              <Row label="Region" value={poolRequest.business_region || 'All'} />
              <Row label="Province" value={poolRequest.province || 'All'} />
              <Row
                label="Age Range"
                value={`${poolRequest.lead_age_from}–${poolRequest.lead_age_to ?? 60} days`}
              />
              <Row label="Distribution" value={poolRequest.distribution_method} />
              <Row label="Requested By" value={poolRequest.requested_by?.name ?? '—'} />
              <Row label="Created" value={fmt(poolRequest.created_at)} />
              {poolRequest.notes && <Row label="Notes" value={poolRequest.notes} />}
            </CardContent>
          </Card>

          {/* Approval form */}
          <Card>
            <CardHeader>
              <CardTitle>Approval Action</CardTitle>
            </CardHeader>
            <CardContent>
              {!showReject ? (
                <form onSubmit={handleApprove} className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="approved_qty">Approved Quantity</Label>
                    <Input
                      id="approved_qty"
                      type="number"
                      min={1}
                      max={Math.min(currentAvailable, poolRequest.requested_quantity)}
                      value={approvedQty}
                      onChange={(e) => setApprovedQty(parseInt(e.target.value) || 1)}
                    />
                    <p className="text-xs text-muted-foreground">
                      Max:{' '}
                      {Math.min(currentAvailable, poolRequest.requested_quantity).toLocaleString()}{' '}
                      (currently available)
                    </p>
                    {!canFulfill && (
                      <p className="text-xs text-orange-600">
                        Only {currentAvailable.toLocaleString()} leads available — adjust the
                        approved quantity above to {currentAvailable.toLocaleString()} or less.
                      </p>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="approve_notes">Approval Notes (optional)</Label>
                    <Textarea
                      id="approve_notes"
                      value={approveNotes}
                      onChange={(e) => setApproveNotes(e.target.value)}
                      rows={2}
                      placeholder="Optional note for the requester..."
                    />
                  </div>
                  <div className="flex gap-3">
                    <Button
                      type="submit"
                      disabled={
                        approveForm.processing || approvedQty > currentAvailable || approvedQty < 1
                      }
                      className="flex-1"
                    >
                      <CheckCircle2 className="mr-2 h-4 w-4" />
                      {approveForm.processing ? 'Approving...' : 'Approve & Reserve'}
                    </Button>
                    <Button type="button" variant="outline" onClick={() => setShowReject(true)}>
                      <XCircle className="mr-2 h-4 w-4" />
                      Reject
                    </Button>
                  </div>
                  {!canFulfill && (
                    <p className="text-xs text-orange-600">
                      Cannot fulfill the full requested quantity. Reduce the approved quantity or
                      reject.
                    </p>
                  )}
                </form>
              ) : (
                <form onSubmit={handleReject} className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="reject_reason">Rejection Reason *</Label>
                    <Textarea
                      id="reject_reason"
                      value={rejectReason}
                      onChange={(e) => setRejectReason(e.target.value)}
                      rows={3}
                      placeholder="Explain why this request is rejected..."
                      required
                    />
                  </div>
                  <div className="flex gap-3">
                    <Button
                      type="submit"
                      variant="destructive"
                      disabled={rejectForm.processing || !rejectReason}
                    >
                      {rejectForm.processing ? 'Rejecting...' : 'Confirm Rejection'}
                    </Button>
                    <Button type="button" variant="outline" onClick={() => setShowReject(false)}>
                      Back to Approve
                    </Button>
                  </div>
                </form>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </TelesalesLayout>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}
