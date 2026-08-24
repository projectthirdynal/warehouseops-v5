import { Head, router } from '@inertiajs/react';
import { ArrowLeft, XCircle } from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

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
  approved_quantity: number | null;
  distribution_method: string;
  status: string;
  notes: string | null;
  rejection_reason: string | null;
  created_at: string;
  approved_at: string | null;
  rejected_at: string | null;
  requested_by: { id: number; name: string };
  approved_by: { id: number; name: string } | null;
  rejected_by: { id: number; name: string } | null;
  pool: {
    id: number;
    pool_number: string;
    status: string;
    reserved_quantity: number;
    distributed_quantity: number;
  } | null;
}

interface Props {
  poolRequest: PoolRequest;
  currentAvailable: number;
  canApprove: boolean;
}

export default function PoolRequestShow({ poolRequest, currentAvailable, canApprove }: Props) {
  const isPending = poolRequest.status === 'PENDING_APPROVAL';
  const canCancel = isPending || poolRequest.status === 'DRAFT';

  const fmt = (d: string | null) =>
    d ? new Date(d).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

  return (
    <TelesalesLayout>
      <Head title={`Pool Request ${poolRequest.request_number}`} />
      <div className="space-y-6 p-6 max-w-4xl">
        <div className="flex items-center gap-4">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => router.visit('/telesales/pool-requests')}
          >
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back
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

        <div className="grid gap-6 md:grid-cols-2">
          {/* Request Details */}
          <Card>
            <CardHeader>
              <CardTitle>Request Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
              <Row label="Brand" value={poolRequest.brand_name} />
              <Row label="Product" value={poolRequest.product_name || 'All'} />
              <Row label="Business Region" value={poolRequest.business_region || 'All'} />
              <Row label="Province" value={poolRequest.province || 'All'} />
              <Row label="City" value={poolRequest.city || 'All'} />
              <Row
                label="Lead Age"
                value={`${poolRequest.lead_age_from}–${poolRequest.lead_age_to ?? 60} days`}
              />
              <Row label="Distribution Method" value={poolRequest.distribution_method} />
              <Row label="Requested By" value={poolRequest.requested_by?.name ?? '—'} />
              <Row label="Created" value={fmt(poolRequest.created_at)} />
              {poolRequest.notes && <Row label="Notes" value={poolRequest.notes} />}
            </CardContent>
          </Card>

          {/* Quantities & Availability */}
          <Card>
            <CardHeader>
              <CardTitle>Quantities</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
              <Row
                label="Requested Quantity"
                value={poolRequest.requested_quantity.toLocaleString()}
                bold
              />
              <Row
                label="Available at Request"
                value={poolRequest.available_quantity_at_request.toLocaleString()}
              />
              <Row
                label="Currently Available"
                value={currentAvailable.toLocaleString()}
                highlight={currentAvailable < poolRequest.requested_quantity ? 'orange' : 'green'}
              />
              {poolRequest.approved_quantity !== null && (
                <Row
                  label="Approved Quantity"
                  value={poolRequest.approved_quantity.toLocaleString()}
                  bold
                />
              )}
              {poolRequest.approved_by && (
                <Row label="Approved By" value={poolRequest.approved_by.name} />
              )}
              {poolRequest.approved_at && (
                <Row label="Approved At" value={fmt(poolRequest.approved_at)} />
              )}
              {poolRequest.rejected_by && (
                <Row label="Rejected By" value={poolRequest.rejected_by.name} />
              )}
              {poolRequest.rejected_at && (
                <Row label="Rejected At" value={fmt(poolRequest.rejected_at)} />
              )}
              {poolRequest.rejection_reason && (
                <Row label="Rejection Reason" value={poolRequest.rejection_reason} />
              )}
            </CardContent>
          </Card>
        </div>

        {/* Linked Pool */}
        {poolRequest.pool && (
          <Card>
            <CardHeader>
              <CardTitle>Approved Pool</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
              <Row label="Pool Number" value={poolRequest.pool.pool_number} bold />
              <Row label="Pool Status" value={poolRequest.pool.status.replace(/_/g, ' ')} />
              <Row label="Reserved" value={poolRequest.pool.reserved_quantity.toLocaleString()} />
              <Row
                label="Distributed"
                value={poolRequest.pool.distributed_quantity.toLocaleString()}
              />
              <Button
                size="sm"
                variant="outline"
                className="mt-2"
                onClick={() => router.visit(`/telesales/pools/${poolRequest.pool!.id}`)}
              >
                View Pool Details
              </Button>
            </CardContent>
          </Card>
        )}

        {/* Actions */}
        {canCancel && (
          <div className="flex justify-end">
            <Button
              variant="outline"
              onClick={() => {
                if (confirm('Cancel this pool request?')) {
                  router.post(
                    `/telesales/pool-requests/${poolRequest.id}/cancel`,
                    {},
                    { preserveState: false }
                  );
                }
              }}
            >
              <XCircle className="mr-2 h-4 w-4" />
              Cancel Request
            </Button>
          </div>
        )}

        {/* Admin approval link */}
        {canApprove && isPending && (
          <div className="flex justify-end">
            <Button onClick={() => router.visit(`/telesales/pool-approvals/${poolRequest.id}`)}>
              Review for Approval
            </Button>
          </div>
        )}
      </div>
    </TelesalesLayout>
  );
}

function Row({
  label,
  value,
  bold,
  highlight,
}: {
  label: string;
  value: string;
  bold?: boolean;
  highlight?: 'green' | 'orange';
}) {
  const colorClass =
    highlight === 'green'
      ? 'text-green-600 font-bold'
      : highlight === 'orange'
        ? 'text-orange-600 font-bold'
        : bold
          ? 'font-bold'
          : '';
  return (
    <div className="flex justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className={colorClass}>{value}</span>
    </div>
  );
}
