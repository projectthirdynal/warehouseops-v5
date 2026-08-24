import { Head, router } from '@inertiajs/react';
import {
  ArrowLeft,
  CheckCircle2,
  XCircle,
  MapPin,
  Phone,
  Package,
  User,
  DollarSign,
} from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';

interface ShopItem {
  id: number;
  product_name: string;
  sku: string | null;
  quantity: number;
  unit_price: number;
  line_total: number;
  metadata: Record<string, unknown> | null;
}

interface Order {
  id: number;
  order_number: string;
  status: string;
  status_label: string;
  quantity: number;
  unit_price: number;
  total_amount: number;
  cod_amount: number;
  discount_amount: number;
  shipping_cost: number;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city: string | null;
  state: string | null;
  barangay: string | null;
  postal_code: string | null;
  landmark: string | null;
  notes: string | null;
  created_at: string;
  lead: {
    id: number;
    name: string;
    phone: string;
    product_name: string | null;
    product_brand: string | null;
  } | null;
  customer: {
    id: number;
    name: string;
    phone: string;
    total_orders: number;
    successful_orders: number;
    risk_level: string | null;
    is_blacklisted: boolean;
  } | null;
  product: { id: number; name: string; sku: string; brand: string } | null;
  agent: { id: number; name: string } | null;
  shop_items: ShopItem[];
}

interface Props {
  order: Order;
}

export default function CheckerDetail({ order }: Props) {
  const [rejectReason, setRejectReason] = useState('');
  const [showRejectForm, setShowRejectForm] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);

  const handleApprove = async () => {
    if (!confirm('Approve this order? It will be sent to courier fulfillment.')) return;
    setIsProcessing(true);
    try {
      const response = await fetch(`/api/checker/orders/${order.id}/approve`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN':
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
      });
      if (response.ok) {
        router.visit('/checker/queue');
      }
    } finally {
      setIsProcessing(false);
    }
  };

  const handleReject = async () => {
    if (!rejectReason.trim()) {
      alert('Please provide a reason for rejection.');
      return;
    }
    setIsProcessing(true);
    try {
      const response = await fetch(`/api/checker/orders/${order.id}/reject`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ reason: rejectReason }),
      });
      if (response.ok) {
        router.visit('/checker/queue');
      }
    } finally {
      setIsProcessing(false);
    }
  };

  const fullAddress = [
    order.receiver_address,
    order.barangay,
    order.city,
    order.state,
    order.postal_code,
  ]
    .filter(Boolean)
    .join(', ');

  return (
    <AppLayout>
      <Head title={`Order ${order.order_number} — Checker`} />
      <div className="space-y-6 p-6 max-w-4xl">
        <div className="flex items-center gap-3">
          <Button variant="ghost" size="sm" onClick={() => router.visit('/checker/queue')}>
            <ArrowLeft className="h-4 w-4 mr-1" />
            Back to Queue
          </Button>
          <h1 className="text-2xl font-bold tracking-tight">{order.order_number}</h1>
          <Badge className="bg-yellow-100 text-yellow-800">{order.status_label}</Badge>
        </div>

        <div className="grid grid-cols-2 gap-6">
          {/* Customer & Delivery Info */}
          <div className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <User className="h-4 w-4" />
                  Customer
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                <div>
                  <span className="text-muted-foreground">Name:</span> {order.receiver_name}
                </div>
                <div className="flex items-center gap-1">
                  <Phone className="h-3 w-3" />
                  <span>{order.receiver_phone}</span>
                </div>
                {order.customer && (
                  <>
                    <div className="pt-2 border-t">
                      <span className="text-muted-foreground">Total Orders:</span>{' '}
                      {order.customer.total_orders}
                    </div>
                    <div>
                      <span className="text-muted-foreground">Successful:</span>{' '}
                      {order.customer.successful_orders}
                    </div>
                    {order.customer.risk_level && (
                      <div>
                        <span className="text-muted-foreground">Risk Level:</span>{' '}
                        <Badge
                          className={
                            order.customer.risk_level === 'high'
                              ? 'bg-red-100 text-red-800'
                              : order.customer.risk_level === 'medium'
                                ? 'bg-yellow-100 text-yellow-800'
                                : 'bg-green-100 text-green-800'
                          }
                        >
                          {order.customer.risk_level}
                        </Badge>
                      </div>
                    )}
                    {order.customer.is_blacklisted && (
                      <Badge className="bg-red-100 text-red-800">Blacklisted</Badge>
                    )}
                  </>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <MapPin className="h-4 w-4" />
                  Delivery Address
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                <div className="bg-muted/50 rounded p-3">{fullAddress || 'No address'}</div>
                {order.landmark && (
                  <div>
                    <span className="text-muted-foreground">Landmark:</span> {order.landmark}
                  </div>
                )}
                {order.notes && (
                  <div className="pt-2 border-t">
                    <span className="text-muted-foreground">Notes:</span> {order.notes}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>

          {/* Order Items & Summary */}
          <div className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <Package className="h-4 w-4" />
                  Order Items
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                {order.shop_items.map((item) => (
                  <div
                    key={item.id}
                    className={`flex justify-between items-start p-2 rounded ${
                      item.metadata?.type === 'FREEBIE' ? 'bg-purple-50' : 'bg-muted/50'
                    }`}
                  >
                    <div>
                      <div className="font-medium text-sm">{item.product_name}</div>
                      <div className="text-xs text-muted-foreground">
                        Qty: {item.quantity} × ₱{item.unit_price.toFixed(2)}
                        {item.metadata?.type === 'FREEBIE' && (
                          <span className="ml-2 text-purple-600">(Freebie)</span>
                        )}
                      </div>
                    </div>
                    <div className="text-sm font-medium">₱{item.line_total.toFixed(2)}</div>
                  </div>
                ))}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <DollarSign className="h-4 w-4" />
                  Order Summary
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <span>Subtotal</span>
                  <span>₱{(order.total_amount + order.discount_amount).toFixed(2)}</span>
                </div>
                {order.discount_amount > 0 && (
                  <div className="flex justify-between text-green-600">
                    <span>Discount</span>
                    <span>-₱{order.discount_amount.toFixed(2)}</span>
                  </div>
                )}
                {order.shipping_cost > 0 && (
                  <div className="flex justify-between">
                    <span>Shipping</span>
                    <span>₱{order.shipping_cost.toFixed(2)}</span>
                  </div>
                )}
                <div className="flex justify-between font-bold text-base border-t pt-2">
                  <span>COD Total</span>
                  <span>₱{order.cod_amount.toFixed(2)}</span>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">Agent</CardTitle>
              </CardHeader>
              <CardContent className="text-sm">
                <div>{order.agent?.name || 'Unassigned'}</div>
                <div className="text-xs text-muted-foreground mt-1">
                  Submitted: {new Date(order.created_at).toLocaleString('en-PH')}
                </div>
              </CardContent>
            </Card>
          </div>
        </div>

        {/* Actions */}
        <div className="flex justify-end gap-3 pt-4 border-t">
          <Button variant="outline" onClick={() => router.visit('/checker/queue')}>
            Skip
          </Button>
          {!showRejectForm ? (
            <>
              <Button
                variant="destructive"
                onClick={() => setShowRejectForm(true)}
                disabled={isProcessing}
              >
                <XCircle className="h-4 w-4 mr-1" />
                Reject
              </Button>
              <Button
                onClick={handleApprove}
                disabled={isProcessing}
                className="bg-green-600 hover:bg-green-700"
              >
                <CheckCircle2 className="h-4 w-4 mr-1" />
                Approve & Send to Courier
              </Button>
            </>
          ) : (
            <div className="w-full max-w-md space-y-2">
              <Label htmlFor="reject_reason">Rejection Reason *</Label>
              <Textarea
                id="reject_reason"
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                placeholder="Why is this order being rejected?"
                rows={3}
              />
              <div className="flex gap-2 justify-end">
                <Button variant="outline" onClick={() => setShowRejectForm(false)}>
                  Cancel
                </Button>
                <Button variant="destructive" onClick={handleReject} disabled={isProcessing}>
                  Confirm Rejection
                </Button>
              </div>
            </div>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
