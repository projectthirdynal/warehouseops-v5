import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
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
import type { Order } from '@/types';

interface Props {
  order: Order;
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

export default function OrderShow({ order }: Props) {
  const [rejectReason, setRejectReason] = useState('');
  const [showReject, setShowReject] = useState(false);

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

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Left column */}
          <div className="lg:col-span-2 space-y-6">
            {/* Order details */}
            <Card>
              <CardHeader><CardTitle className="text-base">Order Details</CardTitle></CardHeader>
              <CardContent className="space-y-3 text-sm">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <span className="text-muted-foreground">Product</span>
                    <p className="font-medium">{order.product?.name ?? 'N/A'}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Quantity</span>
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
