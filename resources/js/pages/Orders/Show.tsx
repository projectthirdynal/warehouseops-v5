import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  AlertTriangle,
  ArrowLeft,
  CheckCircle,
  XCircle,
  Truck,
  Package,
  User,
  MapPin,
  Clock,
  RefreshCw,
  Ban,
} from 'lucide-react';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import type { Order, OrderDuplicateWarning } from '@/types';

interface Props {
  order: Order;
  duplicate_warnings: OrderDuplicateWarning[];
}

const statusColors: Record<string, string> = {
  PENDING: 'bg-gray-100 text-gray-800',
  CONFIRMED: 'bg-blue-100 text-blue-800',
  QA_PENDING: 'bg-yellow-100 text-yellow-800',
  QA_APPROVED: 'bg-green-100 text-green-800',
  QA_REJECTED: 'bg-red-100 text-red-800',
  PROCESSING: 'bg-blue-100 text-blue-800',
  DISPATCHED: 'bg-indigo-100 text-indigo-800',
  DELIVERED: 'bg-green-100 text-green-800',
  RETURNED: 'bg-red-100 text-red-800',
  CANCELLED: 'bg-gray-100 text-gray-600',
};

const resolutionLabels: Record<OrderDuplicateWarning['resolution_status'], string> = {
  pending: 'Pending review',
  continue: 'Kept new order',
  use_existing: 'Kept existing order',
  cancel_new: 'Cancelled new order',
};

export default function OrderShow({ order, duplicate_warnings }: Props) {
  const [rejectReason, setRejectReason] = useState('');
  const [showReject, setShowReject] = useState(false);
  const [resolvingId, setResolvingId] = useState<number | null>(null);

  const handleApprove = () => {
    router.post(`/orders/${order.id}/approve`, {}, { preserveScroll: true });
  };

  const handleReject = () => {
    if (!rejectReason.trim()) return;
    router.post(`/orders/${order.id}/reject`, { reason: rejectReason }, { preserveScroll: true });
  };

  const handleCancel = () => {
    if (confirm('Cancel this order? Stock will be released and lead returned to pool.')) {
      router.post(`/orders/${order.id}/cancel`, {}, { preserveScroll: true });
    }
  };

  const handleRetryCourier = () => {
    router.post(`/orders/${order.id}/retry-courier`, {}, { preserveScroll: true });
  };

  const resolveDuplicateWarning = (warning: OrderDuplicateWarning, decision: OrderDuplicateWarning['resolution_status']) => {
    if (decision === 'pending') {
      return;
    }

    const confirmations: Partial<Record<Exclude<OrderDuplicateWarning['resolution_status'], 'pending'>, string>> = {
      use_existing: 'Keep the existing order and cancel this new order?',
      cancel_new: 'Cancel this new order as a duplicate?',
    };

    if (confirmations[decision] && !confirm(confirmations[decision]!)) {
      return;
    }

    setResolvingId(warning.id);
    router.post(
      `/orders/${order.id}/duplicate-warnings/${warning.id}/resolve`,
      { decision },
      {
        preserveScroll: true,
        onFinish: () => setResolvingId(null),
      }
    );
  };

  const waybill = order.waybill;

  return (
    <AppLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link href="/orders">
              <Button variant="ghost" size="icon"><ArrowLeft className="h-4 w-4" /></Button>
            </Link>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-2xl font-bold font-mono">{order.order_number}</h1>
                <Badge className={statusColors[order.status]}>{order.status.replace('_', ' ')}</Badge>
                {order.courier_code && <Badge variant="outline">{order.courier_code}</Badge>}
              </div>
              <p className="text-sm text-muted-foreground">Created {formatDateTime(order.created_at)}</p>
            </div>
          </div>

          {/* Actions */}
          <div className="flex gap-2">
            {order.status === 'QA_PENDING' && (
              <>
                <Button onClick={handleApprove} className="bg-green-600 hover:bg-green-700">
                  <CheckCircle className="mr-2 h-4 w-4" />Approve
                </Button>
                <Button variant="destructive" onClick={() => setShowReject(!showReject)}>
                  <XCircle className="mr-2 h-4 w-4" />Reject
                </Button>
              </>
            )}
            {order.status === 'PROCESSING' && (
              <Button onClick={handleRetryCourier} variant="outline">
                <RefreshCw className="mr-2 h-4 w-4" />Retry Courier
              </Button>
            )}
            {!['DELIVERED', 'RETURNED', 'CANCELLED', 'QA_REJECTED'].includes(order.status) && (
              <Button variant="outline" onClick={handleCancel}>
                <Ban className="mr-2 h-4 w-4" />Cancel
              </Button>
            )}
          </div>
        </div>

        {/* Reject reason input */}
        {showReject && (
          <Card>
            <CardContent className="p-4">
              <div className="flex gap-3">
                <input
                  type="text"
                  value={rejectReason}
                  onChange={(e) => setRejectReason(e.target.value)}
                  placeholder="Rejection reason (required)"
                  className="flex-1 border rounded-lg px-3 py-2 text-sm"
                  autoFocus
                />
                <Button variant="destructive" onClick={handleReject} disabled={!rejectReason.trim()}>
                  Confirm Rejection
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {order.rejection_reason && (
          <Card className="border-red-200 bg-red-50">
            <CardContent className="p-4 text-sm text-red-800">
              <strong>Rejection reason:</strong> {order.rejection_reason}
            </CardContent>
          </Card>
        )}

        {duplicate_warnings.length > 0 && (
          <Card className="border-amber-200 bg-amber-50/70">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base text-amber-950">
                <AlertTriangle className="h-4 w-4" />
                Duplicate Review
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {duplicate_warnings.map((warning) => {
                const duplicateOrder = warning.duplicate_order;
                const isPending = warning.resolution_status === 'pending';
                const isWorking = resolvingId === warning.id;

                return (
                  <div key={warning.id} className="rounded-lg border border-amber-200 bg-white p-4">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                      <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge className={isPending ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'}>
                            {resolutionLabels[warning.resolution_status]}
                          </Badge>
                          {duplicateOrder && (
                            <>
                              <Badge variant="outline" className={statusColors[duplicateOrder.status]}>
                                {duplicateOrder.status.replace('_', ' ')}
                              </Badge>
                              <Link href={`/orders/${duplicateOrder.id}`} className="text-sm font-medium text-primary hover:underline">
                                {duplicateOrder.order_number}
                              </Link>
                            </>
                          )}
                        </div>
                        <p className="text-sm text-slate-700">{warning.body}</p>
                        {duplicateOrder && (
                          <p className="text-xs text-muted-foreground">
                            Existing order for {duplicateOrder.receiver_name}, created {warning.duplicate_order?.created_at ? formatDateTime(warning.duplicate_order.created_at) : 'earlier'}.
                          </p>
                        )}
                        {!duplicateOrder && warning.duplicate_order_number && (
                          <p className="text-xs text-muted-foreground">
                            Existing order reference: {warning.duplicate_order_number}
                          </p>
                        )}
                        {!isPending && (
                          <p className="text-xs text-muted-foreground">
                            Reviewed {warning.resolved_at ? formatDateTime(warning.resolved_at) : ''}{warning.resolved_by ? ` by ${warning.resolved_by.name}` : ''}.
                          </p>
                        )}
                      </div>

                      {isPending && (
                        <div className="flex flex-col gap-2 sm:flex-row lg:flex-col">
                          <Button size="sm" onClick={() => resolveDuplicateWarning(warning, 'continue')} disabled={isWorking}>
                            Keep New Order
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => resolveDuplicateWarning(warning, 'use_existing')}
                            disabled={isWorking || !duplicateOrder}
                          >
                            Use Existing Order
                          </Button>
                          <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => resolveDuplicateWarning(warning, 'cancel_new')}
                            disabled={isWorking}
                          >
                            Cancel New Order
                          </Button>
                        </div>
                      )}
                    </div>
                  </div>
                );
              })}
            </CardContent>
          </Card>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Left column */}
          <div className="lg:col-span-2 space-y-6">
            {/* Order details */}
            <Card>
              <CardHeader><CardTitle className="text-base">Order Details</CardTitle></CardHeader>
              <CardContent className="space-y-3 text-sm">
                {order.shop_items && order.shop_items.length > 0 ? (
                  <div className="space-y-3">
                    {order.shop_items.map((item) => (
                      <div key={item.id} className="rounded-lg border p-3">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <p className="font-medium">{item.product_name}</p>
                            <p className="text-xs text-muted-foreground">{item.sku ?? item.product?.sku ?? 'No SKU'}</p>
                          </div>
                          <span className="font-medium">{formatCurrency(item.line_total)}</span>
                        </div>
                        <div className="mt-2 grid grid-cols-3 gap-3 text-xs text-muted-foreground">
                          <div>
                            <span>Quantity</span>
                            <p className="font-medium text-foreground">{item.quantity}</p>
                          </div>
                          <div>
                            <span>Unit Price</span>
                            <p className="font-medium text-foreground">{formatCurrency(item.unit_price)}</p>
                          </div>
                          <div>
                            <span>Discount</span>
                            <p className="font-medium text-foreground">{formatCurrency(item.discount_amount ?? 0)}</p>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : null}

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <span className="text-muted-foreground">Primary Product</span>
                    <p className="font-medium">{order.product?.name ?? 'N/A'}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Total Quantity</span>
                    <p className="font-medium">{order.quantity}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Unit Price</span>
                    <p className="font-medium">{formatCurrency(order.unit_price)}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Total Amount</span>
                    <p className="font-semibold text-lg">{formatCurrency(order.total_amount)}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">COD Amount</span>
                    <p className="font-medium">{formatCurrency(order.cod_amount)}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Shipping Cost</span>
                    <p className="font-medium">{formatCurrency(order.shipping_cost)}</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Timeline */}
            <Card>
              <CardHeader><CardTitle className="text-base">Timeline</CardTitle></CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {[
                    { label: 'Created', date: order.created_at, icon: <Clock className="h-4 w-4" /> },
                    { label: 'Confirmed', date: order.confirmed_at, icon: <CheckCircle className="h-4 w-4" /> },
                    { label: 'Dispatched', date: order.dispatched_at, icon: <Truck className="h-4 w-4" /> },
                    { label: 'Delivered', date: order.delivered_at, icon: <Package className="h-4 w-4" /> },
                    { label: 'Returned', date: order.returned_at, icon: <XCircle className="h-4 w-4" /> },
                  ].filter((e) => e.date).map((event) => (
                    <div key={event.label} className="flex items-center gap-3 text-sm">
                      <div className="text-muted-foreground">{event.icon}</div>
                      <span className="font-medium w-24">{event.label}</span>
                      <span className="text-muted-foreground">{formatDateTime(event.date!)}</span>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>

            {/* Waybill info */}
            {waybill && (
              <Card>
                <CardHeader><CardTitle className="text-base">Waybill / Tracking</CardTitle></CardHeader>
                <CardContent className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Tracking Number</span>
                    <span className="font-mono font-semibold">{waybill.waybill_number}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Courier</span>
                    <span>{waybill.courier_provider}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Status</span>
                    <Badge variant="outline">{waybill.status}</Badge>
                  </div>
                  <div className="pt-2">
                    <Link href={`/waybills/${waybill.id}`}>
                      <Button variant="outline" size="sm" className="w-full">View Waybill Details</Button>
                    </Link>
                  </div>
                </CardContent>
              </Card>
            )}
          </div>

          {/* Right column */}
          <div className="space-y-6">
            {/* Receiver */}
            <Card>
              <CardHeader><CardTitle className="text-base">Receiver</CardTitle></CardHeader>
              <CardContent className="space-y-2 text-sm">
                <div className="flex items-center gap-2">
                  <User className="h-4 w-4 text-muted-foreground" />
                  <span className="font-medium">{order.receiver_name}</span>
                </div>
                <p className="text-muted-foreground">{order.receiver_phone}</p>
                <div className="flex items-start gap-2 pt-1">
                  <MapPin className="h-4 w-4 text-muted-foreground shrink-0 mt-0.5" />
                  <p className="text-muted-foreground">
                    {[order.receiver_address, order.barangay, order.city, order.state].filter(Boolean).join(', ')}
                    {order.postal_code && ` ${order.postal_code}`}
                  </p>
                </div>
              </CardContent>
            </Card>

            {/* Agent */}
            <Card>
              <CardHeader><CardTitle className="text-base">Assigned Agent</CardTitle></CardHeader>
              <CardContent className="text-sm">
                <p className="font-medium">{order.agent?.name ?? 'Unassigned'}</p>
                {order.agent?.email && <p className="text-muted-foreground">{order.agent.email}</p>}
              </CardContent>
            </Card>

            {/* Customer */}
            {order.customer && (
              <Card>
                <CardHeader><CardTitle className="text-base">Customer</CardTitle></CardHeader>
                <CardContent className="space-y-1 text-sm">
                  <p className="font-medium">{order.customer.name}</p>
                  <p className="text-muted-foreground">{order.customer.phone}</p>
                  <div className="flex justify-between pt-2 border-t mt-2">
                    <span className="text-muted-foreground">Orders</span>
                    <span>{order.customer.total_orders}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Success Rate</span>
                    <span>{order.customer.success_rate}%</span>
                  </div>
                </CardContent>
              </Card>
            )}

            {/* Notes */}
            {order.notes && (
              <Card>
                <CardHeader><CardTitle className="text-base">Notes</CardTitle></CardHeader>
                <CardContent>
                  <p className="text-sm text-muted-foreground">{order.notes}</p>
                </CardContent>
              </Card>
            )}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
